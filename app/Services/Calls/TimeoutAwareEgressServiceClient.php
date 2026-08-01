<?php

namespace App\Services\Calls;

use Agence104\LiveKit\EgressServiceClient;
use GuzzleHttp\Client as GuzzleClient;
use Livekit\EgressClient as TwirpEgressClient;

class TimeoutAwareEgressServiceClient extends EgressServiceClient
{
    public function __construct(
        ?string $host,
        ?string $apiKey,
        ?string $apiSecret,
        float $connectTimeout = 2.0,
        float $timeout = 10.0,
    ) {
        parent::__construct($host, $apiKey, $apiSecret);

        $this->rpc = new TwirpEgressClient($this->host, new GuzzleClient([
            'connect_timeout' => $connectTimeout,
            'timeout' => $timeout,
            'http_errors' => true,
        ]));
    }
}
