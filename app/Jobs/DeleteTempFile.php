<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DeleteTempFile implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $disk;
    public string $path;

    /**
     * @param string $disk Name des Storage-Disks (z. B. "public")
     * @param string $path Relativer Pfad zur Datei im Disk
     */
    public function __construct(string $disk, string $path)
    {
        $this->disk = $disk;
        $this->path = $path;
    }

    /**
     * Handle the job.
     */
    public function handle(): void
    {
        if (Storage::disk($this->disk)->exists($this->path)) {
            Storage::disk($this->disk)->delete($this->path);
            // Log::info("Temporäre Datei gelöscht: {$this->path}");
        }
    }
}
