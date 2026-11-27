<?php

namespace App\Console\Commands;

use App\Models\OperationInquiry;
use App\Models\Order;
use App\Models\Shift;
use App\Services\Routing\WorksiteLookup;
use App\Support\Operations\TimelineLocationPreview;
use App\Support\Routing\TomTomSettings;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class ResolveWorksites extends Command
{
    protected $signature = 'routing:resolve-worksites {--limit=12 : Maximale Datensätze je Typ und Lauf} {--model= : Modell eines geänderten Einsatzorts} {--id= : Datensatz-ID} {--history : Auch vergangene Einsätze berücksichtigen}';
    protected $description = 'Fehlende Kartenvorschauen für Schichten und Anfragen mit TomTom nachermitteln.';

    public function handle(WorksiteLookup $lookup): int
    {
        $config = TomTomSettings::all();
        if (! $config['enabled'] || ! $config['has_api_key'] || ! $config['server_cache_enabled'] || ! Schema::hasTable('shifts') || ! Schema::hasTable('operation_inquiries')) {
            return self::SUCCESS;
        }
        if ($model = $this->option('model')) {
            $query = match ($model) {
                Shift::class => Shift::with('order'),
                Order::class => Order::query(),
                OperationInquiry::class => OperationInquiry::query(),
                default => null,
            };
            if (! $query || ! ctype_digit((string) $this->option('id'))) {
                return self::INVALID;
            }
            if ($record = $query->find($this->option('id'))) {
                $this->resolveRecord($record, $lookup);
            }
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
                $this->resolveRecord($record, $lookup);
                Cache::put($cursor, $record->id, now()->addWeek());
            }
            if ($records->count() < $limit) {
                Cache::forget($cursor);
            }
        }
        return self::SUCCESS;
    }

    private function resolveRecord(Shift|Order|OperationInquiry $record, WorksiteLookup $lookup): void
    {
        $local = match (true) {
            $record instanceof Shift => TimelineLocationPreview::fromShift($record),
            $record instanceof Order => TimelineLocationPreview::fromOrder($record),
            default => TimelineLocationPreview::fromInquiry($record),
        };
        $input = WorksiteLookup::forRecord($record);
        if (WorksiteLookup::needsLookup($local, $input)) {
            $lookup->resolve($input);
        }
    }
}
