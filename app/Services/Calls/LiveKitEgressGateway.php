<?php

namespace App\Services\Calls;

use App\Contracts\Calls\CallEgressGateway;
use App\Models\RoomRecording;
use App\Support\Calls\EgressSnapshot;
use Livekit\AudioCodec;
use Livekit\EncodedFileOutput;
use Livekit\EncodedFileType;
use Livekit\EncodingOptions;
use Livekit\S3Upload;
use Livekit\VideoCodec;

class LiveKitEgressGateway implements CallEgressGateway
{
    public function isConfigured(): bool
    {
        foreach ([
            config('livekit.url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
            config('call_recording.s3.key'),
            config('call_recording.s3.secret'),
            config('call_recording.s3.region'),
            config('call_recording.s3.bucket'),
        ] as $value) {
            if (trim((string) $value) === '') {
                return false;
            }
        }

        return true;
    }

    public function start(RoomRecording $recording): EgressSnapshot
    {
        $recording->loadMissing('room');

        $s3 = new S3Upload([
            'access_key' => (string) config('call_recording.s3.key'),
            'secret' => (string) config('call_recording.s3.secret'),
            'session_token' => (string) config('call_recording.s3.session_token'),
            'region' => (string) config('call_recording.s3.region'),
            'endpoint' => (string) config('call_recording.s3.endpoint'),
            'bucket' => (string) config('call_recording.s3.bucket'),
            'force_path_style' => (bool) config('call_recording.s3.use_path_style_endpoint'),
            'content_disposition' => 'inline',
        ]);

        $output = new EncodedFileOutput([
            'file_type' => EncodedFileType::MP4,
            'filepath' => $recording->storage_key,
            'disable_manifest' => true,
            's3' => $s3,
        ]);

        // SDK 1.3.5 typisiert den Preset-Parameter irrtuemlich als Enum-Klasse,
        // obwohl protobuf dort einen Integer erwartet. Explizite Advanced-
        // Optionen bilden H264_720P_30 korrekt und ohne TypeError ab.
        $encoding = new EncodingOptions([
            'width' => 1280,
            'height' => 720,
            'framerate' => 30,
            'audio_codec' => AudioCodec::AAC,
            'audio_bitrate' => 128,
            'audio_frequency' => 48000,
            'video_codec' => VideoCodec::H264_MAIN,
            'video_bitrate' => 3000,
        ]);

        $info = $this->client()->startRoomCompositeEgress(
            $recording->room->livekitRoomName(),
            'grid',
            $output,
            $encoding,
            false,
            false,
        );

        return EgressSnapshot::fromLiveKit($info);
    }

    public function stop(string $egressId): EgressSnapshot
    {
        return EgressSnapshot::fromLiveKit($this->client()->stopEgress($egressId));
    }

    public function find(string $egressId): ?EgressSnapshot
    {
        $response = $this->client()->listEgress('', $egressId, false);

        foreach ($response->getItems() as $info) {
            if ($info->getEgressId() === $egressId) {
                return EgressSnapshot::fromLiveKit($info);
            }
        }

        return null;
    }

    protected function client(): TimeoutAwareEgressServiceClient
    {
        return new TimeoutAwareEgressServiceClient(
            config('livekit.url'),
            config('livekit.api_key'),
            config('livekit.api_secret'),
            (float) config('livekit.http_connect_timeout'),
            max(10.0, (float) config('livekit.http_timeout')),
        );
    }
}
