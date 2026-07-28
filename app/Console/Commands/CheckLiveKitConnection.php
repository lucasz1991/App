<?php

namespace App\Console\Commands;

use Agence104\LiveKit\RoomServiceClient;
use App\Services\Calls\LiveKitService;
use Illuminate\Console\Command;

class CheckLiveKitConnection extends Command
{
    protected $signature = 'railtime:livekit-check';

    protected $description = 'Verify the LiveKit server API connection and print the effective call configuration';

    public function handle(LiveKitService $livekit): int
    {
        $this->components->twoColumnDetail('LIVEKIT_URL', (string) config('livekit.url'));
        $this->components->twoColumnDetail('LIVEKIT_WS_URL', (string) config('livekit.ws_url'));
        $this->components->twoColumnDetail('TURN-Modus', (string) config('livekit.turn.mode'));
        $this->components->twoColumnDetail('Ring-Timeout', config('livekit.ring_timeout').'s');
        $this->components->twoColumnDetail('Token-TTL', config('livekit.token_ttl').'s');
        $this->components->twoColumnDetail('Webhook-Endpunkt', route('webhooks.livekit'));

        if (! $livekit->isConfigured()) {
            $this->components->error('LIVEKIT_API_KEY/LIVEKIT_API_SECRET fehlen – bitte zuerst `php artisan railtime:livekit-keys` ausfuehren.');

            return self::FAILURE;
        }

        try {
            $client = new RoomServiceClient(
                config('livekit.url'),
                config('livekit.api_key'),
                config('livekit.api_secret'),
            );

            $response = $client->listRooms();
            $count = count(iterator_to_array($response->getRooms()));

            $this->components->info("Server-API erreichbar – aktive Raeume: {$count}.");
        } catch (\Throwable $exception) {
            $this->components->error('Server-API nicht erreichbar: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (config('livekit.turn.mode') === 'coturn' && trim((string) config('livekit.turn.url')) === '') {
            $this->components->warn('TURN-Modus "coturn" ohne TURN_URL – Clients erhalten keinen Fallback.');
        }

        return self::SUCCESS;
    }
}
