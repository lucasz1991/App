<?php

namespace App\Services\Calls;

use App\Contracts\Calls\CallEgressGateway;
use App\Enums\RoomRecordingStatus;
use App\Jobs\EnforceCallRecordingStartDeadline;
use App\Jobs\StartRoomRecording;
use App\Jobs\StopRoomRecording;
use App\Models\CallRecordingAcknowledgement;
use App\Models\LiveKitWebhookReceipt;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\RoomRecording;
use App\Models\User;
use App\Support\Calls\EgressSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livekit\EgressStatus;
use Livekit\WebhookEvent;
use Throwable;

class CallRecordingService
{
    /** @var array<int, string> */
    public const EGRESS_WEBHOOK_EVENTS = [
        'egress_started',
        'egress_updated',
        'egress_ended',
    ];

    public function __construct(
        protected CallEgressGateway $egress,
        protected LiveKitService $livekit,
        protected RoomEventRecorder $events,
    ) {
    }

    public function enabled(): bool
    {
        return (bool) config('call_recording.enabled', false);
    }

    public function infrastructureReady(): bool
    {
        return $this->enabled() && $this->egress->isConfigured();
    }

    public function policyVersion(): string
    {
        return (string) config('call_recording.policy_version');
    }

    public function acknowledge(User $user, string $policyVersion): CallRecordingAcknowledgement
    {
        abort_unless($this->enabled(), 404);
        abort_unless(hash_equals($this->policyVersion(), $policyVersion), 422);

        return CallRecordingAcknowledgement::firstOrCreate(
            [
                'user_id' => $user->id,
                'policy_version' => $policyVersion,
            ],
            ['accepted_at' => now()],
        );
    }

    public function currentAcknowledgement(User $user): ?CallRecordingAcknowledgement
    {
        if (! $this->enabled()) {
            return null;
        }

        return CallRecordingAcknowledgement::query()
            ->where('user_id', $user->id)
            ->where('policy_version', $this->policyVersion())
            ->first();
    }

    public function attachAcknowledgement(
        RoomParticipant $participant,
        CallRecordingAcknowledgement $acknowledgement,
    ): void {
        if ((int) $participant->call_recording_acknowledgement_id === (int) $acknowledgement->id) {
            return;
        }

        $participant->forceFill([
            'call_recording_acknowledgement_id' => $acknowledgement->id,
        ])->save();
    }

    public function ensureRequested(Room $room): RoomRecording
    {
        $created = false;

        $recording = DB::transaction(function () use ($room, &$created): RoomRecording {
            $existing = RoomRecording::query()
                ->where('room_id', $room->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $created = true;
            $uuid = (string) Str::uuid();
            $scopeUuid = (string) Str::uuid();
            $requestedAt = now();

            return RoomRecording::create([
                'uuid' => $uuid,
                'room_id' => $room->id,
                'storage_scope_uuid' => $scopeUuid,
                'status' => RoomRecordingStatus::Requested,
                'storage_disk' => (string) config('call_recording.storage_disk', 'call_recordings'),
                'storage_key' => "recordings/{$scopeUuid}/{$room->uuid}/{$uuid}.mp4",
                'mime_type' => 'video/mp4',
                'requested_at' => $requestedAt,
                'start_deadline_at' => $requestedAt->copy()->addSeconds(
                    max(1, (int) config('call_recording.start_deadline_seconds', 30)),
                ),
            ]);
        });

        if ($created) {
            $this->events->record(
                $room,
                'recording_requested',
                $room->owner_id,
                ['recording_uuid' => $recording->uuid],
                'recording:'.$recording->uuid.':requested',
            );
        }

        if ($created || $recording->status === RoomRecordingStatus::Requested) {
            StartRoomRecording::dispatch($recording->id)
                ->onQueue((string) config('call_recording.queue', 'calls'))
                ->afterCommit();

            EnforceCallRecordingStartDeadline::dispatch($recording->id)
                ->delay($recording->start_deadline_at)
                ->onQueue((string) config('call_recording.queue', 'calls'))
                ->afterCommit();
        }

        return $recording->fresh();
    }

    /** Startet Egress. Rueckgabe bedeutet: der Raum muss fail-closed enden. */
    public function start(RoomRecording $recording): ?Room
    {
        $recording = DB::transaction(function () use ($recording): ?RoomRecording {
            $locked = RoomRecording::query()->lockForUpdate()->find($recording->id);

            if (! $locked || $locked->status !== RoomRecordingStatus::Requested) {
                return null;
            }

            $locked->forceFill(['status' => RoomRecordingStatus::Starting])->save();

            $this->recordStatusEvent($locked, RoomRecordingStatus::Starting);

            return $locked;
        });

        if (! $recording) {
            return null;
        }

        try {
            $snapshot = $this->egress->start($recording);
        } catch (Throwable $exception) {
            $this->markFailed($recording, 'egress_start_failed', $exception->getMessage());

            Log::warning('Verpflichtende Call-Aufzeichnung konnte nicht starten.', [
                'recording_uuid' => $recording->uuid,
                'room_uuid' => $recording->room->uuid,
                'error_class' => $exception::class,
            ]);

            return $recording->room;
        }

        return $this->applySnapshot($recording, $snapshot);
    }

    public function enforceStartDeadline(RoomRecording $recording): ?Room
    {
        $recording->refresh();

        if (
            ! in_array($recording->status, [RoomRecordingStatus::Requested, RoomRecordingStatus::Starting], true)
            || $recording->start_deadline_at->isFuture()
        ) {
            return null;
        }

        $this->markFailed($recording, 'egress_start_timeout', 'Aufzeichnung wurde nicht innerhalb des Startfensters aktiv.');

        return $recording->room;
    }

    public function requestStop(Room $room): void
    {
        if (! $this->enabled()) {
            return;
        }

        $recording = DB::transaction(function () use ($room): ?RoomRecording {
            $recording = RoomRecording::query()
                ->where('room_id', $room->id)
                ->lockForUpdate()
                ->first();

            if (! $recording || $recording->status->isTerminal()) {
                return null;
            }

            if (! $recording->livekit_egress_id) {
                $recording->forceFill([
                    'status' => RoomRecordingStatus::Aborted,
                    'error_code' => 'call_ended_before_recording_start',
                    'error_message' => 'Der Anruf endete vor dem Start der Aufzeichnung.',
                    'completed_at' => now(),
                ])->save();

                $this->recordStatusEvent($recording, RoomRecordingStatus::Aborted);

                return null;
            }

            if ($recording->status !== RoomRecordingStatus::Ending) {
                $recording->forceFill(['status' => RoomRecordingStatus::Ending])->save();
                $this->recordStatusEvent($recording, RoomRecordingStatus::Ending);
            }

            return $recording;
        });

        if ($recording) {
            StopRoomRecording::dispatch($recording->id)
                ->onQueue((string) config('call_recording.queue', 'calls'))
                ->afterCommit();
        }
    }

    public function stop(RoomRecording $recording): void
    {
        $recording->refresh();

        if ($recording->status !== RoomRecordingStatus::Ending || ! $recording->livekit_egress_id) {
            return;
        }

        try {
            $snapshot = $this->egress->stop($recording->livekit_egress_id);
            $this->applySnapshot($recording, $snapshot);
        } catch (Throwable $exception) {
            // Room-Ende beendet Egress ebenfalls. Ein verlorener Stop-Response
            // darf deshalb nicht vorschnell eine fertige Datei als Fehler markieren;
            // der minuetliche Abgleich oder egress_ended klaert den Endzustand.
            Log::warning('LiveKit-Egress konnte nicht explizit gestoppt werden.', [
                'recording_uuid' => $recording->uuid,
                'error_class' => $exception::class,
            ]);
        }
    }

    /** Egress-Webhooks werden vor allen room-/participant-Payloads verarbeitet. */
    public function handleWebhook(WebhookEvent $event): ?Room
    {
        if (! in_array($event->getEvent(), self::EGRESS_WEBHOOK_EVENTS, true)) {
            return null;
        }

        $info = $event->getEgressInfo();

        if (! $info) {
            return null;
        }

        $snapshot = EgressSnapshot::fromLiveKit($info);
        $eventId = trim((string) $event->getId());
        $eventId = $eventId !== '' ? $eventId : hash('sha256', implode('|', [
            $event->getEvent(),
            $snapshot->egressId,
            $snapshot->status,
            (string) $event->getCreatedAt(),
        ]));

        if (LiveKitWebhookReceipt::where('event_id', $eventId)->exists()) {
            return null;
        }

        $recording = RoomRecording::query()
            ->where('livekit_egress_id', $snapshot->egressId)
            ->first();

        if (! $recording && $snapshot->roomName !== '') {
            $recording = RoomRecording::query()
                ->whereHas('room', fn ($query) => $query->where('uuid', $snapshot->roomName))
                ->first();
        }

        if (! $recording) {
            LiveKitWebhookReceipt::firstOrCreate(
                ['event_id' => $eventId],
                ['event_type' => $event->getEvent(), 'received_at' => now()],
            );

            return null;
        }

        $room = DB::transaction(function () use ($recording, $snapshot, $event, $eventId): ?Room {
            if (LiveKitWebhookReceipt::where('event_id', $eventId)->lockForUpdate()->exists()) {
                return null;
            }

            $locked = RoomRecording::query()->lockForUpdate()->findOrFail($recording->id);
            $roomToEnd = $this->persistSnapshot($locked, $snapshot);

            LiveKitWebhookReceipt::create([
                'event_id' => $eventId,
                'event_type' => $event->getEvent(),
                'received_at' => now(),
            ]);

            return $roomToEnd;
        });

        $recording->refresh();

        if ($recording->status === RoomRecordingStatus::Active) {
            $this->unlockJoinedPublishers($recording->room);
        }

        return $room;
    }

    public function reconcile(RoomRecording $recording): ?Room
    {
        $recording->refresh();

        if (! in_array($recording->status, [
            RoomRecordingStatus::Requested,
            RoomRecordingStatus::Starting,
            RoomRecordingStatus::Active,
            RoomRecordingStatus::Ending,
        ], true)) {
            return null;
        }

        if (! $recording->livekit_egress_id) {
            return $this->enforceStartDeadline($recording);
        }

        try {
            $snapshot = $this->egress->find($recording->livekit_egress_id);
        } catch (Throwable $exception) {
            Log::warning('LiveKit-Egress-Abgleich nicht erreichbar.', [
                'recording_uuid' => $recording->uuid,
                'error_class' => $exception::class,
            ]);

            return null;
        }

        if ($snapshot) {
            return $this->applySnapshot($recording, $snapshot);
        }

        // Ein verlorener End-Webhook kann durch die bereits vorhandene Datei
        // sicher rekonstruiert werden, ohne interne S3-Orte aus LiveKit zu vertrauen.
        try {
            if ($recording->storage_key && Storage::disk($recording->storage_disk)->exists($recording->storage_key)) {
                $recording->forceFill([
                    'status' => RoomRecordingStatus::Complete,
                    'completed_at' => $recording->completed_at ?? now(),
                    'expires_at' => $recording->expires_at ?? now()->addDays((int) config('call_recording.retention_days', 90)),
                    'size_bytes' => Storage::disk($recording->storage_disk)->size($recording->storage_key),
                    'error_code' => null,
                    'error_message' => null,
                ])->save();

                return $recording->room->isActive() ? $recording->room : null;
            }
        } catch (Throwable $exception) {
            Log::warning('Call-Aufzeichnungsdatei konnte beim Abgleich nicht geprueft werden.', [
                'recording_uuid' => $recording->uuid,
                'error_class' => $exception::class,
            ]);

            return null;
        }

        if ($recording->status === RoomRecordingStatus::Active && $recording->room->isActive()) {
            $this->markFailed($recording, 'egress_missing', 'Aktive Aufzeichnung wurde von LiveKit nicht mehr gemeldet.');

            return $recording->room;
        }

        if (
            $recording->status === RoomRecordingStatus::Ending
            && $recording->updated_at->lte(now()->subMinutes(5))
        ) {
            $this->markFailed($recording, 'egress_result_missing', 'LiveKit lieferte keinen Abschluss fuer die Aufzeichnung.');
        }

        return null;
    }

    public function unlockParticipantIfReady(Room $room, string $identity): void
    {
        $recording = RoomRecording::query()->where('room_id', $room->id)->first();

        if (! $recording || $recording->status !== RoomRecordingStatus::Active) {
            return;
        }

        $participant = $room->participants()
            ->where('livekit_identity', $identity)
            ->first();

        if ($participant?->canPublish()) {
            $this->livekit->setParticipantPublishing($room, $identity, true);
        }
    }

    protected function applySnapshot(RoomRecording $recording, EgressSnapshot $snapshot): ?Room
    {
        $room = DB::transaction(function () use ($recording, $snapshot): ?Room {
            $locked = RoomRecording::query()->lockForUpdate()->findOrFail($recording->id);

            return $this->persistSnapshot($locked, $snapshot);
        });

        $recording->refresh();

        if ($recording->status === RoomRecordingStatus::Active) {
            $this->unlockJoinedPublishers($recording->room);
        }

        return $room;
    }

    protected function persistSnapshot(RoomRecording $recording, EgressSnapshot $snapshot): ?Room
    {
        if ($recording->status === RoomRecordingStatus::Expired) {
            return null;
        }

        if ($snapshot->roomName !== '' && $snapshot->roomName !== $recording->room->livekitRoomName()) {
            return null;
        }

        $status = match ($snapshot->status) {
            EgressStatus::EGRESS_STARTING => RoomRecordingStatus::Starting,
            EgressStatus::EGRESS_ACTIVE => RoomRecordingStatus::Active,
            EgressStatus::EGRESS_ENDING => RoomRecordingStatus::Ending,
            EgressStatus::EGRESS_COMPLETE => RoomRecordingStatus::Complete,
            EgressStatus::EGRESS_ABORTED => RoomRecordingStatus::Aborted,
            EgressStatus::EGRESS_FAILED, EgressStatus::EGRESS_LIMIT_REACHED => RoomRecordingStatus::Failed,
            default => RoomRecordingStatus::Failed,
        };

        // Ein finaler Zustand darf durch ein verspaetetes STARTING/ACTIVE-Ereignis
        // nie wieder geoeffnet werden.
        if ($recording->status->isTerminal() && ! $status->isTerminal()) {
            return null;
        }

        $attributes = [
            'livekit_egress_id' => $recording->livekit_egress_id ?: $snapshot->egressId,
            'status' => $status,
            'started_at' => $recording->started_at ?? $snapshot->startedAt,
            'size_bytes' => $snapshot->sizeBytes ?? $recording->size_bytes,
            'duration_ms' => $snapshot->durationMs ?? $recording->duration_ms,
        ];

        if ($status === RoomRecordingStatus::Complete) {
            $completedAt = $snapshot->endedAt ?? now();
            $attributes += [
                'completed_at' => $completedAt,
                'expires_at' => $completedAt->copy()->addDays((int) config('call_recording.retention_days', 90)),
                'error_code' => null,
                'error_message' => null,
            ];
        } elseif (in_array($status, [RoomRecordingStatus::Failed, RoomRecordingStatus::Aborted], true)) {
            $attributes += [
                'completed_at' => $snapshot->endedAt ?? now(),
                'error_code' => $this->sanitizeError($snapshot->errorCode ?: 'egress_terminated', 100),
                'error_message' => $this->sanitizeError($snapshot->errorMessage ?: 'LiveKit beendete die Aufzeichnung unerwartet.', 1000),
            ];
        }

        $previousStatus = $recording->status;
        $recording->forceFill($attributes)->save();

        if ($previousStatus !== $status) {
            $this->recordStatusEvent($recording, $status);
        }

        // COMPLETE waehrend eines noch laufenden Raums ist ebenfalls ein
        // Aufnahmeabbruch: Ab jetzt wuerde unaufgezeichnet weitergesprochen.
        if ($status !== RoomRecordingStatus::Active && $recording->room->isActive()) {
            if (in_array($status, [RoomRecordingStatus::Complete, RoomRecordingStatus::Failed, RoomRecordingStatus::Aborted], true)) {
                return $recording->room;
            }
        }

        return null;
    }

    protected function unlockJoinedPublishers(Room $room): void
    {
        $room->participants()
            ->where('connection', 'joined')
            ->get()
            ->filter(fn (RoomParticipant $participant): bool => $participant->canPublish() && (bool) $participant->livekit_identity)
            ->each(fn (RoomParticipant $participant) => $this->livekit->setParticipantPublishing(
                $room,
                $participant->livekit_identity,
                true,
            ));
    }

    protected function markFailed(RoomRecording $recording, string $code, string $message): void
    {
        $recording->forceFill([
            'status' => RoomRecordingStatus::Failed,
            'completed_at' => now(),
            'error_code' => $this->sanitizeError($code, 100),
            'error_message' => $this->sanitizeError($message, 1000),
        ])->save();

        $this->recordStatusEvent($recording, RoomRecordingStatus::Failed);
    }

    protected function recordStatusEvent(RoomRecording $recording, RoomRecordingStatus $status): void
    {
        $type = match ($status) {
            RoomRecordingStatus::Requested => 'recording_requested',
            RoomRecordingStatus::Starting => 'recording_starting',
            RoomRecordingStatus::Active => 'recording_started',
            RoomRecordingStatus::Ending => 'recording_stopping',
            RoomRecordingStatus::Complete => 'recording_completed',
            RoomRecordingStatus::Failed, RoomRecordingStatus::Aborted => 'recording_failed',
            RoomRecordingStatus::Expired => 'recording_expired',
        };

        $this->events->record(
            $recording->room,
            $type,
            metadata: [
                'recording_uuid' => $recording->uuid,
                'status' => $status->value,
            ],
            externalId: 'recording:'.$recording->uuid.':'.$status->value,
        );
    }

    protected function sanitizeError(string $value, int $limit): string
    {
        $value = strip_tags($value);
        $value = preg_replace('#https?://\S+#i', '[url]', $value) ?? $value;
        $value = preg_replace('/\b(secret|token|key|credential)\s*[=:]\s*\S+/i', '$1=[redacted]', $value) ?? $value;
        $value = preg_replace('/\s+/', ' ', $value) ?? $value;

        return Str::limit(trim($value), $limit, '');
    }
}
