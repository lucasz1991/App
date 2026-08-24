<?php

namespace App\Console\Commands;

use App\Support\Mail\EmailCompatibilityCatalog;
use App\Support\Mail\EmailCompatibilityCatalogException;
use Illuminate\Console\Command;

final class CheckEmailCompatibilityCatalog extends Command
{
    protected $signature = 'mail:compatibility-catalog:check';

    protected $description = 'Prueft Erreichbarkeit und Vertrag des versionierten E-Mail-Kompatibilitaetskatalogs';

    public function handle(EmailCompatibilityCatalog $catalog): int
    {
        try {
            $schemaVersion = $catalog->schemaVersion();
            $catalogVersion = $catalog->catalogVersion();
            $rows = count($catalog->rows());
            $rules = count($catalog->activeRuleGroups());
        } catch (EmailCompatibilityCatalogException $exception) {
            $this->components->error(
                '['.$exception->errorCode.'] '.$exception->getMessage(),
            );

            return self::FAILURE;
        }

        $this->components->twoColumnDetail('Katalogpfad', $catalog->path);
        $this->components->twoColumnDetail('Schema', $schemaVersion);
        $this->components->twoColumnDetail('Katalogversion', $catalogVersion);
        $this->components->twoColumnDetail('Profilzeilen', (string) $rows);
        $this->components->twoColumnDetail('Regeln', (string) $rules);
        $this->components->info('E-Mail-Kompatibilitaetskatalog ist erreichbar und gueltig.');

        return self::SUCCESS;
    }
}
