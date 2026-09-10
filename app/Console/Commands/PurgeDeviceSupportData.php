<?php

namespace App\Console\Commands;

use App\Models\EmployeeWorkplaceProvision;
use App\Models\SupportCase;
use App\Models\SupportCaseAttachment;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class PurgeDeviceSupportData extends Command
{
    protected $signature = 'devices:purge-support-data';

    protected $description = 'Entfernt ausschließlich abgelaufene Diagnosedaten und kurzzeitig hinterlegte Startkennwörter.';

    public function handle(): int
    {
        DB::table('device_withdrawal_receipts')->where('expires_at', '<=', now())->delete();
        $diagnostics = SupportCase::query()->where('diagnostics_expires_at', '<=', now())->whereNotNull('diagnostics')->update(['diagnostics' => null]);
        $passwords = EmployeeWorkplaceProvision::query()->where('password_expires_at', '<=', now())->whereNotNull('initial_password')->update(['initial_password' => null]);
        SupportCaseAttachment::query()->where('expires_at', '<=', now())->chunkById(50, function ($files): void {
            foreach ($files as $file) {
                if ($file->disk !== 'private' || ! preg_match('~^device-support/[a-f0-9-]{36}/[a-f0-9-]{36}\.enc$~', $file->path)) {
                    continue;
                }
                $disk = Storage::disk('private');
                if (! $disk->exists($file->path) || $disk->delete($file->path)) {
                    $file->delete();
                }
            }
        });
        $this->info('Abgelaufene Diagnosen: '.$diagnostics.'; abgelaufene Startkennwörter: '.$passwords.'.');

        return self::SUCCESS;
    }
}
