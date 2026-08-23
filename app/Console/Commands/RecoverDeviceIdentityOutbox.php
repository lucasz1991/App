<?php

namespace App\Console\Commands;

use App\Services\DeviceManagement\DeviceIdentitySyncService;
use Illuminate\Console\Command;

final class RecoverDeviceIdentityOutbox extends Command
{
    protected $signature = 'devices:recover-identity-outbox
        {--limit=100 : Maximale Anzahl persistierter Outbox-Zeilen}
        {--stale-minutes=10 : Mindestalter eines queued Eintrags vor erneutem Queue-Dispatch}';

    protected $description = 'Recover persisted device identity outbox rows without bypassing the production gate';

    public function handle(DeviceIdentitySyncService $syncs): int
    {
        $limit = filter_var($this->option('limit'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 500],
        ]);
        $staleMinutes = filter_var($this->option('stale-minutes'), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 1440],
        ]);
        if ($limit === false || $staleMinutes === false) {
            $this->components->error('Limit und stale-minutes müssen innerhalb der zulässigen Grenzen liegen.');

            return self::INVALID;
        }

        $result = $syncs->recoverPending($limit, $staleMinutes);
        if (! $result['gate_released']) {
            $this->components->warn('Identity-Produktionsfreigabe geschlossen; keine Outbox-Zeile wurde versendet.');

            return self::SUCCESS;
        }

        $this->components->twoColumnDetail('Queued wieder eingeplant', (string) $result['queued_recovered']);
        $this->components->twoColumnDetail('Blockierte freigegeben', (string) $result['blocked_released']);
        $this->components->twoColumnDetail('Veralteter Kontext blockiert', (string) $result['stale_context']);

        return self::SUCCESS;
    }
}
