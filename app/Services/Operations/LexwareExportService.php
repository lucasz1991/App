<?php

namespace App\Services\Operations;

use App\Models\LexwareExportRun;
use App\Models\LexwareProfile;
use App\Models\OperationsMonthClosing;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

class LexwareExportService
{
    public static function sample(): string
    {
        return LexwareMonthlyFile::sample();
    }

    public function inspect(User $actor, int $closingId): array
    {
        $closing = $this->authorizedClosing($actor, $closingId);
        $state = [
            'available' => false, 'reason' => null, 'closing_revision' => $closing->revision,
            'profile_version' => null, 'employer_reference' => null, 'company_name' => null,
            'personnel_number' => null, 'rows' => [], 'skipped_zero' => 0, 'run' => null, 'runs' => [],
        ];
        try {
            $this->requireTables();
            $run = LexwareExportRun::where('closing_id', $closing->id)->first();
            if ($run) {
                $state['run'] = $this->runMetadata($run);
                $state['runs'] = [$state['run']];
            }
            [$profile, $snapshot, $file] = $this->preflight($closing, $run, false);
            $state['available'] = true;
            $state['profile_version'] = $profile->config_version;
            $state['employer_reference'] = $profile->employer_reference;
            $state['company_name'] = $profile->company_name;
            $state['personnel_number'] = $snapshot['payroll_reference']['personnel_number'];
            $state['rows'] = $file['rows'];
            $state['skipped_zero'] = $file['skipped_zero'];
        } catch (ValidationException $error) {
            $state['reason'] = $error->errors()['lexware'][0];
        }

        return $state;
    }

    public function download(User $actor, int $closingId, int $expectedRevision, int $expectedProfileVersion, bool $firstTransferConfirmed = false): array
    {
        return OperationsTransaction::run(function () use ($actor, $closingId, $expectedRevision, $expectedProfileVersion, $firstTransferConfirmed) {
            $closing = $this->authorizedClosing($actor, $closingId, true);
            $this->requireTables();
            $this->check($closing->revision === $expectedRevision, 'Der Monatsabschluss wurde geändert. Bitte die Vorschau neu öffnen.');
            $run = LexwareExportRun::where('closing_id', $closing->id)->lockForUpdate()->first();
            [$profile, $snapshot, $file] = $this->preflight($closing, $run, true);
            $this->check($profile->config_version === $expectedProfileVersion, 'Das Exportprofil wurde geändert. Bitte die Vorschau neu öffnen.');
            $existing = $run !== null;
            if (! $existing) {
                $this->check($firstTransferConfirmed, 'Bitte Zielfirma, Personalnummer und erstmalige Übergabe bestätigen.');
                $run = LexwareExportRun::create([
                    'closing_id' => $closing->id, 'closing_revision' => $closing->revision,
                    'profile_id' => $profile->id, 'profile_version' => $profile->config_version,
                    'target_key' => $this->targetKey($profile, $closing, $snapshot),
                    'sha256' => hash('sha256', $file['content']), 'payload' => $file['content'],
                    'positions' => array_map(static fn (array $row) => ['target_code' => $row['target_code'], 'value' => $row['value']], $file['rows']),
                    'created_by' => $actor->id, 'created_at' => now()->utc(), 'status' => 'open',
                ]);
            }
            $payload = $run->payload;
            $this->check(hash_equals($run->sha256, hash('sha256', $payload)), 'Die gespeicherte Datei stimmt nicht mit der Prüfsumme überein.');
            app(OperationsAuditService::class)->record($closing, $actor, $existing ? 'lexware.file.downloaded_again' : 'lexware.file.created',
                ['run_id' => $run->id, 'closing_revision' => $run->closing_revision, 'profile_id' => $profile->id, 'profile_version' => $run->profile_version,
                    'sha256' => $run->sha256, 'skipped_zero' => $file['skipped_zero'], 'first_transfer_confirmed' => ! $existing]);

            return ['filename' => "Lexware-{$closing->month}-Lauf-{$run->id}.txt", 'content' => $payload];
        });
    }

    public function recordResult(User $actor, int $closingId, string $result, int $expectedRunId, array $importedTargetCodes = []): void
    {
        OperationsTransaction::run(function () use ($actor, $closingId, $result, $expectedRunId, $importedTargetCodes) {
            $closing = $this->authorizedClosing($actor, $closingId, true);
            $this->requireTables();
            $this->check(in_array($result, ['full', 'partial', 'rejected'], true), 'Das angegebene Importergebnis ist ungültig.');
            $run = LexwareExportRun::where('closing_id', $closing->id)->whereKey($expectedRunId)->lockForUpdate()->first();
            $this->check($run !== null, 'Dieser Exportlauf gehört nicht zum geöffneten Monatsabschluss.');
            $codes = array_column($run->positions, 'target_code');
            $imported = match ($result) {
                'full' => $codes,
                'partial' => $this->partialCodes($importedTargetCodes, $codes),
                default => [],
            };
            if ($run->status === $result && $run->imported_codes === $imported) {
                return;
            }
            $this->check($run->status === 'open', 'Das Ergebnis für diesen Lauf wurde bereits eingetragen.');
            $run->forceFill(['status' => $result, 'imported_codes' => $imported, 'result_by' => $actor->id, 'result_at' => now()->utc()])->save();
            app(OperationsAuditService::class)->record($closing, $actor, 'lexware.import.recorded',
                ['run_id' => $run->id, 'closing_revision' => $run->closing_revision, 'profile_version' => $run->profile_version,
                    'result' => $result, 'imported_codes' => $imported]);
        });
    }

    private function authorizedClosing(User $actor, int $id, bool $lock = false): OperationsMonthClosing
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.time.export');
        $closing = OperationsMonthClosing::query()->when($lock, fn ($query) => $query->lockForUpdate())->findOrFail($id);
        app(PersonnelScopeService::class)->authorize($actor, (int) $closing->user_id, 'operations.time.export');

        return $closing;
    }

    private function preflight(OperationsMonthClosing $closing, ?LexwareExportRun $run, bool $lock): array
    {
        $this->check($closing->status === 'closed', 'Für die Übergabe muss der Monat aktuell abgeschlossen sein.');
        $sealed = $closing->revisions()->where('revision', $closing->revision)->where('action', 'closed')->first();
        $this->check($sealed && is_array($sealed->snapshot), 'Für diese Monatsrevision fehlt der freigegebene Abschlussstand.');
        $snapshot = $sealed->snapshot;
        $employer = $snapshot['payroll_reference']['employer_reference'] ?? null;
        $this->check(is_string($employer) && $employer !== '', 'Im abgeschlossenen Monat fehlt die Arbeitgeberreferenz. Die Lohnzuordnung muss im Abschluss enthalten sein.');
        $profile = LexwareProfile::where('employer_reference', $employer)->when($lock, fn ($query) => $query->lockForUpdate())->first();
        $this->check($profile && $profile->employer_reference === $employer && $profile->active && $profile->tested_at && $profile->tested_version === $profile->config_version,
            'Die Lexware-Übergabe ist für diese Firma deaktiviert oder der Probeimport für die aktuelle Profilversion fehlt.');
        if ($run) {
            $this->check($run->closing_revision === $closing->revision && (int) $run->profile_id === (int) $profile->id && $run->profile_version === $profile->config_version,
                'Für diesen Monat existiert bereits eine Vollübergabe mit anderem Abschluss- oder Profilstand. Vor einer Korrektur den vorhandenen Import in Lexware abgleichen.');
        }
        $file = LexwareMonthlyFile::build($closing, $snapshot, $profile->mappings ?? []);
        if ($run) {
            $this->check(hash_equals($run->sha256, hash('sha256', $file['content'])), 'Der Dateiinhalt weicht vom protokollierten Export ab. Übergabe gesperrt.');
        } else {
            $duplicate = LexwareExportRun::where('target_key', $this->targetKey($profile, $closing, $snapshot))->exists();
            $this->check(! $duplicate, 'Für diese Firma, Personalnummer und diesen Monat besteht bereits ein Exportlauf.');
        }

        return [$profile, $snapshot, $file];
    }

    private function targetKey(LexwareProfile $profile, OperationsMonthClosing $closing, array $snapshot): string
    {
        $number = strtolower($snapshot['payroll_reference']['personnel_number']);
        if (ctype_digit($number)) {
            $number = ltrim($number, '0') ?: '0';
        }

        return hash('sha256', implode('|', [$profile->id, $closing->month, $number]));
    }

    private function partialCodes(array $selected, array $codes): array
    {
        $this->check(array_is_list($selected) && count($selected) > 0 && count($selected) < count($codes),
            'Für einen Teilimport bitte die tatsächlich übernommenen Lohnpositionen auswählen.');
        foreach ($selected as $code) {
            $this->check(is_string($code) && in_array($code, $codes, true), 'Die ausgewählte Lohnart gehört nicht zu diesem Exportlauf.');
        }
        $valid = array_values(array_intersect($codes, $selected));
        $this->check(count($valid) === count($selected), 'Eine Lohnposition wurde mehrfach ausgewählt.');

        return $valid;
    }

    private function runMetadata(LexwareExportRun $run): array
    {
        return [
            'id' => $run->id, 'closing_revision' => $run->closing_revision, 'profile_version' => $run->profile_version,
            'sha256' => $run->sha256, 'status' => $run->status,
            'rows' => $run->positions, 'target_codes' => array_column($run->positions, 'target_code'), 'imported_codes' => $run->imported_codes ?? [],
            'created_at' => $run->created_at->toIso8601String(), 'result_at' => $run->result_at?->toIso8601String(),
        ];
    }

    private function requireTables(): void
    {
        $this->check(Schema::hasTable('lexware_profiles') && Schema::hasTable('lexware_export_runs'), 'Die Lexware-Schnittstelle ist in dieser Installation noch nicht eingerichtet.');
    }

    private function check(bool $condition, string $message): void
    {
        if (! $condition) {
            throw ValidationException::withMessages(['lexware' => $message]);
        }
    }
}
