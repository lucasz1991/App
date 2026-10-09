<?php

namespace App\Console\Commands;

use App\Models\DropboxConnection;
use App\Models\User;
use App\Services\Dropbox\ConnectionManager;
use App\Services\Dropbox\WorkbookPeopleImporter;
use App\Services\Dropbox\WorkbookReader;
use Illuminate\Console\Command;

class ImportDropboxPeople extends Command
{
    protected $signature = 'dropbox:people {connection} {matrix : Employee overview XLSX} {--weekly=* : Optional weekly XLSX files} {--actor=1} {--apply}';

    protected $description = 'Mitarbeiter aus ausdrücklicher Excel-Mitarbeiterübersicht übernehmen, ohne Einladungen oder Testkennzeichnung';

    public function handle(WorkbookReader $reader, WorkbookPeopleImporter $importer): int
    {
        $actor = User::findOrFail((int) $this->option('actor'));
        app(ConnectionManager::class)->authorize($actor);
        $connection = DropboxConnection::remote()->findOrFail((int) $this->argument('connection'));
        $entries = [];
        $files = [['path' => $this->argument('matrix'), 'profile' => 'matrix']];
        foreach ($this->option('weekly') as $file) {
            $files[] = ['path' => $file, 'profile' => 'weekly'];
        }
        foreach ($files as $file) {
            $parsed = $reader->read(file_get_contents($file['path']), $file['profile']);
            array_push($entries, ...array_filter($parsed['rows'], fn ($row) => $row['domain'] === 'contacts'));
        }
        if (! $this->option('apply')) {
            $this->line(json_encode(['employee_rows' => count(array_filter($entries, fn ($e) => $e['locator']['kind'] === 'employee')), 'provider_rows' => count(array_filter($entries, fn ($e) => $e['locator']['kind'] === 'provider'))]));

            return self::SUCCESS;
        }
        $this->line(json_encode($importer->import($connection, $entries, $actor)));

        return self::SUCCESS;
    }
}
