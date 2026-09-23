<?php

namespace App\Console\Commands;

use App\Services\Dropbox\WorkbookPackage;
use App\Services\Dropbox\WorkbookReader;
use App\Services\Dropbox\WorkbookWriter;
use Illuminate\Console\Command;

class InspectDropboxWorkbook extends Command
{
    protected $signature = 'dropbox:inspect {file : Local XLSX path} {--profile=weekly} {--roundtrip : Verify a targeted in-memory edit}';

    protected $description = 'Excel-Struktur und unveränderte Paketbestandteile prüfen; Originaldatei bleibt unverändert';

    public function handle(WorkbookReader $reader): int
    {
        $path = $this->argument('file');
        if (! is_file($path) || ! in_array($this->option('profile'), ['weekly', 'matrix'], true)) {
            $this->error('Datei oder Profil ungültig.');

            return self::FAILURE;
        }
        $bytes = file_get_contents($path);
        $before = hash('sha256', $bytes);
        $profile = $this->option('profile');
        $parsed = $reader->read($bytes, $profile);
        $counts = array_count_values(array_column($parsed['rows'], 'domain'));
        $verification = null;
        if ($this->option('roundtrip')) {
            $samples = [];
            foreach ($parsed['rows'] as $entry) {
                if ($entry['domain'] === 'planning' && (! $entry['values']['starts'] || ! $entry['values']['ends'])) {
                    continue;
                }
                $key = $entry['domain'].':'.($entry['locator']['kind'] ?? ($entry['locator']['master'] ? 'master' : 'personal'));
                if (isset($samples[$key])) {
                    continue;
                }
                $values = $entry['values'];
                $field = match ($entry['domain']) {
                    'competencies' => 'value', 'contacts' => 'phone', default => 'notes'
                };
                $values[$field] = 'RailTime-Kompatibilitätsprüfung';
                $samples[$key] = ['entry' => $entry, 'values' => $values];
            }
            if (! $samples) {
                $this->error('Keine geeignete Zelle gefunden.');

                return self::FAILURE;
            }
            $out = app(WorkbookWriter::class)->write($bytes, array_values($samples), $profile);
            $old = new WorkbookPackage($bytes);
            $new = new WorkbookPackage($out);
            $changedParts = ['xl/styles.xml'];
            $preserved = 0;
            foreach ($samples as $sample) {
                $changedParts[] = $old->sheets()[$sample['entry']['sheet']];
            }
            foreach ($old->partHashes() as $name => $hash) {
                if (in_array($name, $changedParts, true)) {
                    continue;
                }
                if ($new->partHashes()[$name] !== $hash) {
                    throw new \RuntimeException('Unbetroffener Bestandteil verändert.');
                }
                $preserved++;
            }
            $verification = ['tested_profiles' => array_keys($samples), 'unchanged_parts' => $preserved, 'target_value_reread' => true, 'desktop_excel_verified' => false, 'browser_excel_verified' => false];
        }
        if (hash_file('sha256', $path) !== $before) {
            throw new \RuntimeException('Originaldatei verändert.');
        }
        $this->line(json_encode(['file' => basename($path), 'profile' => $profile, 'sheets' => count($parsed['sheets']), 'records' => $counts, 'issues' => count($parsed['issues']), 'weeks' => $parsed['weeks'], 'roundtrip' => $verification, 'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1), 'original_unchanged' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
