<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Support\OutlookAddin\OutlookUniversalDiagnosticFixtureStore;
use Illuminate\Console\Command;

final class PurgeOutlookDiagnosticFixtures extends Command
{
    protected $signature = 'outlook-addin:purge-diagnostic-fixtures';

    protected $description = 'Check expiry of exactly three private U2e pilot diagnostic cache slots.';

    public function handle(OutlookUniversalDiagnosticFixtureStore $fixtures): int
    {
        if (! $fixtures->purgeExpired()) {
            $this->error('The private U2e diagnostic cache expiry could not be checked.');

            return self::FAILURE;
        }
        $this->info('3 private U2e pilot cache slots checked for expiry.');

        return self::SUCCESS;
    }
}
