<?php

namespace App\Console\Commands;

use App\Services\Routing\ServerRoutingCache;
use Illuminate\Console\Command;

class PurgeRoutingCache extends Command
{
    protected $signature = 'routing:purge-cache';

    protected $description = 'Remove expired entries from the private shared routing cache.';

    public function handle(ServerRoutingCache $cache): int
    {
        $this->info($cache->purgeExpired().' expired routing cache entries removed.');

        return self::SUCCESS;
    }
}
