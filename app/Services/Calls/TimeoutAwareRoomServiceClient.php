<?php

namespace App\Services\Calls;

use Agence104\LiveKit\RoomServiceClient;
use GuzzleHttp\Client as GuzzleClient;
use Livekit\RoomServiceClient as TwirpRoomServiceClient;

/**
 * RoomServiceClient mit gesetzten HTTP-Timeouts.
 *
 * Das SDK baut seinen Twirp-Client ohne HTTP-Client
 * (Agence104\LiveKit\RoomServiceClient::__construct), weshalb
 * Psr18ClientDiscovery::find() einen blanken Guzzle ohne connect_timeout und
 * ohne timeout liefert. Ein haengender Media-Server (DNS-Blackhole, verlorene
 * Route, ueberlastete VM) wuerde damit jeden Server-API-Aufruf bis zum
 * PHP-Timeout blockieren – und weil startCall() synchron createRoom() ruft,
 * haelt jeder Anrufversuch einen PHP-FPM-Worker fest, bis der Pool leer ist.
 */
class TimeoutAwareRoomServiceClient extends RoomServiceClient
{
    public function __construct(
        ?string $host,
        ?string $apiKey,
        ?string $apiSecret,
        float $connectTimeout = 2.0,
        float $timeout = 5.0,
    ) {
        parent::__construct($host, $apiKey, $apiSecret);

        $this->rpc = new TwirpRoomServiceClient($this->host, new GuzzleClient([
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
            'http_errors' => true,
        ]));
    }
}
