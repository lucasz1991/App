<?php

namespace App\Console\Commands;

use App\Services\Ai\AssistantKnowledgeDefaultsImporter;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class ImportAssistantKnowledgeDefaults extends Command
{
    protected $signature = 'railtime:assistant-knowledge-import
        {--dry-run : Nur geplante Änderungen ermitteln}';

    protected $description = 'Importiert das kuratierte RailTime-Standardwissen idempotent in den Assistenz-Wissenspool.';

    public function handle(AssistantKnowledgeDefaultsImporter $importer): int
    {
        try {
            $result = $importer->import(
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (InvalidArgumentException|RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            // Unexpected infrastructure exceptions can contain SQL bindings.
            // Keep command output free of imported content and source details.
            $this->components->error('Der Standardwissensimport konnte nicht ausgeführt werden.');

            return self::FAILURE;
        }

        $this->components->info(($result['dry_run'] ? 'Vorschau' : 'Import').' · Revision '.$result['revision']);
        $this->table(['Bereich', 'Neu', 'Aktualisiert', 'Unverändert', 'Übersprungen'], [
            ['Themen', $result['topics_created'], $result['topics_updated'], $result['topics_unchanged'], $result['topics_skipped']],
            ['Einträge', $result['entries_created'], $result['entries_updated'], $result['entries_unchanged'], $result['entries_skipped']],
        ]);

        if ((int) $result['baseline_deferred'] > 0) {
            $this->components->warn($result['baseline_deferred'].' Basisinfos wurden wegen des Acht-Einträge-Limits nur für die Suche importiert.');
        }

        if ((int) $result['conflicts'] > 0) {
            $this->components->warn($result['conflicts'].' Namenskonflikte wurden ohne Überschreiben übersprungen.');
        }

        if ((int) $result['customized_skipped'] > 0 || (int) $result['deleted_skipped'] > 0) {
            $this->components->warn(
                $result['customized_skipped'].' angepasste und '
                .$result['deleted_skipped'].' gelöschte Standarddatensätze wurden unverändert bewahrt.',
            );
        }

        return self::SUCCESS;
    }
}
