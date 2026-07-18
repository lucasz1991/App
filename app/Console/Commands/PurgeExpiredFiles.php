<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\File;
use App\Models\FileFolder;
use Illuminate\Support\Facades\Log;

class PurgeExpiredFiles extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'files:purge-expired';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Loescht abgelaufene Dateien und Ordner, die auf automatisches Loeschen gesetzt sind.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $deletedFiles = 0;
        $deletedFolders = 0;

        // Abgelaufene Dateien mit auto_delete entfernen. Der Model-Guard
        // isExpiredForDeletion() entscheidet endgueltig, damit die
        // Ablaufgrenze exakt mit dem Modell uebereinstimmt.
        $files = File::where('auto_delete', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->get();

        foreach ($files as $file) {
            if (! $file->isExpiredForDeletion()) {
                continue;
            }

            try {
                $file->delete();
                $deletedFiles++;
            } catch (\Throwable $e) {
                Log::warning('Konnte abgelaufene Datei nicht loeschen', [
                    'file_id' => $file->id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        // Abgelaufene Ordner mit auto_delete rekursiv entfernen (inkl.
        // Unterordner, Dateien und Blobs). Der Model-Guard bestimmt die
        // End-of-Day-Grenze, damit sie mit dem Modell uebereinstimmt.
        $folders = FileFolder::where('auto_delete', true)
            ->whereNotNull('visible_until')
            ->get();

        foreach ($folders as $folder) {
            if (! $folder->isExpiredForDeletion()) {
                continue;
            }

            try {
                $folder->deleteRecursive();
                $deletedFolders++;
            } catch (\Throwable $e) {
                Log::warning('Konnte abgelaufenen Ordner nicht loeschen', [
                    'folder_id' => $folder->id,
                    'error'     => $e->getMessage(),
                ]);
            }
        }

        $summary = "Bereinigung abgeschlossen: {$deletedFiles} Datei(en) und {$deletedFolders} Ordner geloescht.";

        $this->info($summary);
        Log::info($summary);

        return self::SUCCESS;
    }
}
