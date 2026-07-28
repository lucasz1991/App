<?php

namespace App\Services\Calls;

use Agence104\LiveKit\AccessToken;
use Agence104\LiveKit\AccessTokenOptions;
use Agence104\LiveKit\RoomCreateOptions;
use Agence104\LiveKit\RoomServiceClient;
use Agence104\LiveKit\VideoGrant;
use App\Models\Room;
use App\Models\RoomParticipant;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Livekit\ParticipantPermission;

/**
 * Kapselt saemtliche Aufrufe gegen den LiveKit-Server (Tokens + Server-API).
 *
 * Sicherheitsgrundsatz: Browser-Tokens erhalten niemals roomCreate –
 * Raeume entstehen ausschliesslich serverseitig ueber diese Klasse.
 */
class LiveKitService
{
    public function isConfigured(): bool
    {
        return (string) config('livekit.api_key') !== ''
            && (string) config('livekit.api_secret') !== '';
    }

    /** Die LiveKit-Identity eines Nutzers ("user-{id}"), Abgleichpunkt fuer Webhooks. */
    public static function identityFor(User|int $user): string
    {
        $id = $user instanceof User ? $user->id : $user;

        return 'user-'.$id;
    }

    /** Signiertes Beitritts-Token mit exakt den Rechten der Teilnehmerrolle. */
    public function issueToken(Room $room, User $user, RoomParticipant $participant): string
    {
        $options = (new AccessTokenOptions())
            ->setIdentity(self::identityFor($user))
            ->setName($user->name)
            ->setTtl((int) config('livekit.token_ttl'))
            ->setMetadata(json_encode(['role' => $participant->role]));

        $grant = (new VideoGrant())
            ->setRoomJoin()
            ->setRoomName($room->livekitRoomName())
            ->setCanSubscribe()
            ->setCanPublishData()
            ->setCanPublish($participant->canPublish());

        if ($participant->isModerator() || $room->canModerate($user)) {
            $grant->setRoomAdmin();
        }

        return (new AccessToken(config('livekit.api_key'), config('livekit.api_secret')))
            ->init($options)
            ->setGrant($grant)
            ->toJwt();
    }

    /**
     * ICE-Server fuer die Clients. Im Modus "embedded" handelt LiveKit sein
     * eingebautes TURN selbst aus; bei "coturn" liefern wir die statischen
     * Zugangsdaten des externen Servers mit.
     *
     * @return array<int, array<string, mixed>>
     */
    public function iceServers(): array
    {
        if (config('livekit.turn.mode') !== 'coturn') {
            return [];
        }

        $url = (string) config('livekit.turn.url');

        if ($url === '') {
            return [];
        }

        return [[
            'urls' => [$url],
            'username' => (string) config('livekit.turn.username'),
            'credential' => (string) config('livekit.turn.credential'),
        ]];
    }

    /** Raum serverseitig anlegen; scheitert leise, wenn LiveKit nicht erreichbar ist. */
    public function createRoom(Room $room): void
    {
        $this->guarded(function () use ($room): void {
            $options = (new RoomCreateOptions())
                ->setName($room->livekitRoomName())
                ->setEmptyTimeout((int) config('livekit.empty_timeout'))
                ->setMaxParticipants((int) ($room->settings['max_participants'] ?? config('livekit.max_participants')));

            $this->client()->createRoom($options);
        }, 'createRoom', $room);
    }

    /** Raum serverseitig beenden – wirft alle Teilnehmer, room_finished folgt per Webhook. */
    public function deleteRoom(Room $room): void
    {
        $this->guarded(function () use ($room): void {
            $this->client()->deleteRoom($room->livekitRoomName());
        }, 'deleteRoom', $room);
    }

    /** Audio-Track eines Teilnehmers serverseitig stummschalten. */
    public function muteParticipant(Room $room, string $identity, string $trackSid, bool $muted = true): void
    {
        $this->guarded(function () use ($room, $identity, $trackSid, $muted): void {
            $this->client()->mutePublishedTrack($room->livekitRoomName(), $identity, $trackSid, $muted);
        }, 'muteParticipant', $room);
    }

    /** Alle Audio-Tracks eines Teilnehmers serverseitig stummschalten. */
    public function muteParticipantAudio(Room $room, string $identity): void
    {
        $this->guarded(function () use ($room, $identity): void {
            $info = $this->client()->getParticipant($room->livekitRoomName(), $identity);

            foreach ($info->getTracks() as $track) {
                // TrackType::AUDIO = 0 (livekit_models.proto)
                if ($track->getType() === 0 && ! $track->getMuted()) {
                    $this->client()->mutePublishedTrack($room->livekitRoomName(), $identity, $track->getSid(), true);
                }
            }
        }, 'muteParticipantAudio', $room);
    }

    /** Teilnehmer serverseitig aus dem Raum entfernen. */
    public function removeParticipant(Room $room, string $identity): void
    {
        $this->guarded(function () use ($room, $identity): void {
            $this->client()->removeParticipant($room->livekitRoomName(), $identity);
        }, 'removeParticipant', $room);
    }

    /** Publish-Rechte live umschalten (Rollenwechsel Sprecher <-> nur zuhoeren). */
    public function setParticipantPublishing(Room $room, string $identity, bool $canPublish): void
    {
        $this->guarded(function () use ($room, $identity, $canPublish): void {
            $permission = (new ParticipantPermission())
                ->setCanSubscribe(true)
                ->setCanPublish($canPublish)
                ->setCanPublishData(true);

            $this->client()->updateParticipant($room->livekitRoomName(), $identity, null, $permission);
        }, 'setParticipantPublishing', $room);
    }

    protected function client(): RoomServiceClient
    {
        return new RoomServiceClient(
            config('livekit.url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
        );
    }

    /**
     * Server-API-Aufrufe duerfen die UX nie hart brechen: Die DB bleibt die
     * fuehrende Wahrheit, LiveKit heilt sich ueber Webhooks und empty_timeout.
     */
    protected function guarded(callable $callback, string $action, Room $room): void
    {
        if (! $this->isConfigured()) {
            Log::notice('LiveKit ist nicht konfiguriert – Server-Aufruf uebersprungen.', [
                'action' => $action,
                'room' => $room->uuid,
            ]);

            return;
        }

        try {
            $callback();
        } catch (\Throwable $exception) {
            Log::warning('LiveKit-Server-Aufruf fehlgeschlagen.', [
                'action' => $action,
                'room' => $room->uuid,
                'error_class' => $exception::class,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
