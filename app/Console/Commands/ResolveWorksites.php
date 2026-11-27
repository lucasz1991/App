<?php

namespace App\Console\Commands;

use App\Models\OperationInquiry;
use App\Models\Shift;
use App\Services\Routing\WorksiteLookup;
use App\Support\Operations\TimelineLocationPreview;
use App\Support\Routing\TomTomSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ResolveWorksites extends Command
{
    protected $signature = 'routing:resolve-worksites {--limit=12 : Maximale Datensätze je Typ und Lauf} {--history : Auch vergangene Einsätze berücksichtigen}';
    protected $description = 'Fehlende Kartenvorschauen für Schichten und Anfragen mit TomTom nachermitteln.';

    public function handle(WorksiteLookup $lookup): int
    {
        $config = TomTomSettings::all();
        if (! $config['enabled'] || ! $config['has_api_key'] || ! $config['server_cache_enabled'] || ! Schema::hasTable('shifts') || ! Schema::hasTable('operation_inquiries')) {
            return self::SUCCESS;
        }
        $limit = max(1, min(100, (int) $this->option('limit')));
        $history = $this->option('history');
        $shifts = Shift::query()->notCancelled()->with('order');
        $inquiries = OperationInquiry::query()->whereNull('duplicate_of_id')->whereNull('order_id');
        if (! $history) {
            $shifts->where('ends_at', '>', now()->startOfDay())->where('starts_at', '<', now()->addWeek());
            $inquiries->whereIn('status', ['new', 'verified', 'offered', 'accepted']);
        }
        foreach (['shifts' => $shifts, 'inquiries' => $inquiries] as $type => $query) {
            $cursor = 'worksite:cursor:'.$type.($history ? ':all' : ':active');
            $records = $query->where('id', '>', Cache::get($cursor, 0))->orderBy('id')->limit($limit)->get();
            foreach ($records as $record) {
                $local = $record instanceof Shift ? TimelineLocationPreview::fromShift($record) : TimelineLocationPreview::fromInquiry($record);
                $input = WorksiteLookup::forRecord($record);
                if (WorksiteLookup::needsLookup($local, $input)) {
                    $lookup->resolve($input);
                }
                Cache::put($cursor, $record->id, now()->addWeek());
            }
            if ($records->count() < $limit) {
                Cache::forget($cursor);
            }
        }
        return self::SUCCESS;
    }
}
