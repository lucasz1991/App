<?php

namespace App\Console\Commands;

use App\Models\DropboxConnection;
use App\Services\Dropbox\ClosingService;
use App\Services\Dropbox\SyncHealth;
use App\Services\Dropbox\WorkLedger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

class RecoverDropboxSync extends Command
{
    protected $signature = 'dropbox:recover';

    protected $description = 'Dauerhafte Dropbox-Arbeit nach Queue-Ausfällen erneut einplanen und Cursor kontrollieren';

    public function handle(WorkLedger $ledger): int
    {
        if (! Schema::hasTable('dropbox_connections')) {
            return self::SUCCESS;
        }
        try {
            app(SyncHealth::class)->probe();
        } catch (\Throwable) {
            $this->warn('Dropbox-Redis nicht erreichbar; SQL-Arbeit bleibt erhalten.');
        }
        foreach (DropboxConnection::whereNotNull('refresh_token')->get() as $connection) {
            if (! $connection->mode->active()) {
                continue;
            }
            app(ClosingService::class)->advance($connection);
            if (! $connection->checked_at || $connection->checked_at->isBefore(now()->subMinutes($connection->option('check_minutes')))) {
                $ledger->enqueue($connection, 'scan', 'scan');
            }
        }
        $this->info($ledger->dispatch().' Arbeitsaufträge an Redis übergeben.');

        return self::SUCCESS;
    }
}
