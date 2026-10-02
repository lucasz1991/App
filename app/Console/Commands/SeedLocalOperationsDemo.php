<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Operations\LocalDemoDataService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class SeedLocalOperationsDemo extends Command
{
    protected $signature = 'operations:seed-local-demo
        {--date= : Bezugsdatum YYYY-MM-DD, standardmäßig heute in Europe/Berlin}
        {--weeks-before=1 : Vergangene Wochen}
        {--weeks-after=4 : Kommende Wochen}
        {--templates=16 : Anzahl verschiedener Excel-Vorlagen}
        {--actor= : ID des verantwortlichen lokalen Administrators}
        {--dry-run : Vorschau ohne Datenbankänderungen}';

    protected $description = 'Ergänzt markierte lokale Testpläne aus vorhandenen Excel-Importen ohne Originaldaten zu ändern.';

    public function handle(LocalDemoDataService $service): int
    {
        try {
            $service->assertLocal();
            $date = $this->option('date') ?: CarbonImmutable::now(LocalDemoDataService::TIMEZONE)->toDateString();
            $anchor = CarbonImmutable::createFromFormat('!Y-m-d', $date, LocalDemoDataService::TIMEZONE);
            if (! $anchor || $anchor->toDateString() !== $date) {
                throw new \RuntimeException('Das Bezugsdatum muss ein gültiges Datum im Format YYYY-MM-DD sein.');
            }
            foreach (['weeks-before', 'weeks-after', 'templates', 'actor'] as $option) {
                if ($this->option($option) !== null && ! preg_match('/^\d+$/D', (string) $this->option($option))) {
                    throw new \RuntimeException('Option --'.$option.' muss eine ganze nichtnegative Zahl sein.');
                }
            }
            $actor = User::where('status', true)->where('role', 'admin')
                ->when($this->option('actor'), fn ($q, $id) => $q->whereKey($id))->orderByDesc('id')->firstOrFail();
            $preview = (bool) $this->option('dry-run');
            $result = Cache::store('file')->lock('operations.seed-local-demo', 1800)->block(2, fn () => $service->generate(
                $anchor, $actor, (int) $this->option('weeks-before'), (int) $this->option('weeks-after'),
                (int) $this->option('templates'), $preview,
                fn ($done, $total) => $done % 16 === 0 ? $this->line('Testleistungen vorbereitet: '.$done.'/'.$total) : null,
            ));
            $this->info(($preview ? 'Vorschau: ' : 'Lokale Testdaten: ').$result['from'].' bis '.$result['until'].' (Europe/Berlin)');
            $this->table(['Inhalt', 'Anzahl'], [
                ['Excel-Vorlagen', $result['templates']], ['Verfügbare Excel-Testmitarbeiter', $result['employees_available']],
                [$preview ? 'Neue Leistungen geplant' : 'Leistungen erstellt', $preview ? $result['orders_planned'] : $result['orders_created']],
                [$preview ? 'Neue Schichten geplant' : 'Schichten erstellt', $preview ? $result['shifts_planned'] : $result['shifts_created']],
                ['Zuweisungen erstellt', $result['assignments_created']], ['Abwesenheiten erstellt', $result['absences_created']],
                ['Zeitmeldungen erstellt', $result['time_entries_created']], ['Bestehende Testleistungen beibehalten', $result['orders_skipped']],
            ]);
            if (! $preview) {
                $path = 'private/local-demo/'.$anchor->format('Ymd').'-'.now()->format('His').'.json';
                Storage::disk('local')->put($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                $this->line('Lokaler Laufbericht: storage/app/'.$path);
            }

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->error($error instanceof ValidationException ? implode(' ', $error->validator->errors()->all()) : $error->getMessage());

            return self::FAILURE;
        }
    }
}
