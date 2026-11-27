<?php

namespace App\Services\Operations;

use App\Models\EmployeePayrollReference;
use App\Models\LexwareProfile;
use App\Models\OperationAudit;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LexwareProfileService
{
    public const ABILITY = 'operations.lexware.manage';

    private const FIELDS = ['company_name', 'import_profile', 'product_version', 'mappings'];

    public static function ready(): bool
    {
        return Schema::hasTable('lexware_profiles') && Schema::hasTable('employee_payroll_references') && Schema::hasTable('operation_audits');
    }

    public function canManage(User $actor): bool
    {
        return $actor->status && $actor->can(self::ABILITY);
    }

    public function employers(User $actor): array
    {
        $this->requireReady($actor);
        $ids = app(PersonnelScopeService::class)->visibleUserIds($actor, self::ABILITY);
        $refs = EmployeePayrollReference::query();
        if ($ids === null) {
            return $refs->select('employer_reference')->union(LexwareProfile::select('employer_reference'))
                ->orderBy('employer_reference')->pluck('employer_reference')->all();
        }

        // A company configuration affects every employee in that payroll company.
        return $refs->whereIn('user_id', $ids)
            ->whereNotIn('employer_reference', EmployeePayrollReference::select('employer_reference')->whereNotIn('user_id', $ids))
            ->distinct()->orderBy('employer_reference')->pluck('employer_reference')->all();
    }

    public function get(string $employer, User $actor): ?LexwareProfile
    {
        $this->requireReady($actor);
        $this->authorizeCompany($employer, $actor);

        return LexwareProfile::where('employer_reference', $employer)->first();
    }

    public function history(string $employer, User $actor): array
    {
        $record = $this->get($employer, $actor);
        if (! $record) {
            return [];
        }

        return OperationAudit::where('subject_type', class_basename($record))->where('subject_id', $record->id)
            ->whereIn('action', ['lexware.profile.saved', 'lexware.profile.test_confirmed', 'lexware.profile.activated', 'lexware.profile.deactivated'])
            ->orderByDesc('id')->limit(20)->get()->map(fn ($row) => [
                'event' => substr($row->action, strlen('lexware.profile.')),
                'config_version' => $row->data['config_version'],
                'created_at' => $row->created_at->toIso8601String(),
                'actor_id' => $row->actor_id,
            ])->all();
    }

    public function save(string $employer, int $expectedRevision, array $data, User $actor): LexwareProfile
    {
        $this->requireReady($actor);

        return OperationsTransaction::run(function () use ($employer, $expectedRevision, $data, $actor) {
            $this->authorizeCompany($employer, $actor, lock: true);
            $record = $this->locked($employer, $expectedRevision);
            $data = $this->normalize($data);
            if ($record && $this->normalize($record->only(self::FIELDS)) === $data) {
                return $record;
            }

            $record ??= new LexwareProfile(['employer_reference' => $employer, 'config_version' => 0, 'revision' => 0]);
            $previouslyActive = (bool) $record->active;
            $record->fill($data);
            $record->config_version++;
            $record->active = false;
            $this->clearTest($record);
            $this->persist($record, $actor, 'saved', $previouslyActive, ['settings' => $data]);

            return $record;
        });
    }

    public function confirmTest(string $employer, int $expectedRevision, User $actor): LexwareProfile
    {
        return $this->transition($employer, $expectedRevision, $actor, 'test_confirmed');
    }

    public function activate(string $employer, int $expectedRevision, User $actor): LexwareProfile
    {
        return $this->transition($employer, $expectedRevision, $actor, 'activated');
    }

    public function deactivate(string $employer, int $expectedRevision, User $actor): LexwareProfile
    {
        return $this->transition($employer, $expectedRevision, $actor, 'deactivated');
    }

    public function missing(array $data): array
    {
        $reasons = [];
        if (blank($data['company_name'] ?? null)) {
            $reasons[] = 'Der Firmenname aus der Windows-Installation fehlt.';
        }
        if (blank($data['import_profile'] ?? null)) {
            $reasons[] = 'Der Name des verwendeten Windows-Importprofils fehlt.';
        }
        $mappings = $data['mappings'] ?? [];
        if (! is_array($mappings) || ! $mappings) {
            $reasons[] = 'Mindestens eine Lohnart zuordnen.';

            return $reasons;
        }

        $sources = [];
        $targets = [];
        foreach (array_values($mappings) as $index => $mapping) {
            $source = is_string($mapping['source_code'] ?? null) ? trim($mapping['source_code']) : '';
            $target = is_string($mapping['target_code'] ?? null) ? trim($mapping['target_code']) : '';
            $kind = $mapping['quantity_kind'] ?? '';
            if (! preg_match('/\A[A-Za-z0-9_.-]{1,40}\z/', $source)
                || ! preg_match('/\A[0-9]{1,4}\z/', $target) || (int) $target === 0
                || ! in_array($kind, ['base', 'supplement'], true)) {
                $reasons[] = 'Zuordnung '.($index + 1).': RailTime-Lohnart, Lexware-Lohnart (0001–9999) und Mengenart vervollständigen.';
                continue;
            }
            if (isset($sources[$source])) {
                $reasons[] = 'RailTime-Lohnart '.$source.' ist mehrfach zugeordnet.';
            }
            $sources[$source] = true;
            $targets[str_pad($target, 4, '0', STR_PAD_LEFT)][] = $mapping;
        }

        foreach ($targets as $target => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            if (count(array_unique(array_column($rows, 'quantity_kind'))) !== 1) {
                $reasons[] = 'Lexware-Lohnart '.$target.' darf Grundstunden und Zuschlagsstunden nicht zusammenfassen.';
            }
            if (collect($rows)->contains(fn ($row) => ! in_array($row['disjoint'] ?? false, [true, 1, '1'], true))) {
                $reasons[] = 'Für alle Zuordnungen zu Lexware-Lohnart '.$target.' getrennte, überschneidungsfreie Mengen bestätigen.';
            }
        }

        return array_values(array_unique($reasons));
    }

    private function transition(string $employer, int $expectedRevision, User $actor, string $event): LexwareProfile
    {
        $this->requireReady($actor);

        return OperationsTransaction::run(function () use ($employer, $expectedRevision, $actor, $event) {
            $references = $this->authorizeCompany($employer, $actor, lock: true);
            $record = $this->locked($employer, $expectedRevision);
            if (! $record) {
                throw ValidationException::withMessages(['workflow' => 'Zuerst einen Entwurf für diese Firma speichern.']);
            }
            $previouslyActive = $record->active;
            if ($event === 'deactivated') {
                if (! $record->active && ! $record->tested_at) {
                    return $record;
                }
                $record->active = false;
                $this->clearTest($record);
            } else {
                $missing = $this->missing($record->only(self::FIELDS));
                if (collect($references)->contains(fn ($ref) => $ref['employer_reference'] !== $employer)) {
                    $missing[] = 'Abweichende Schreibweisen der Arbeitgeberreferenz in den Lohnzuordnungen vereinheitlichen.';
                }
                if (! $references || collect($references)->contains(fn ($ref) => ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,18}\z/', $ref['personnel_number']))) {
                    $missing[] = 'Die Firma benötigt gültige Personalnummern aus der Lohnzuordnung (maximal 19 Zeichen).';
                }
                if ($missing) {
                    throw ValidationException::withMessages(['workflow' => $missing]);
                }
                if ($event === 'test_confirmed') {
                    if ($record->active) {
                        throw ValidationException::withMessages(['workflow' => 'Das Profil ist bereits aktiv.']);
                    }
                    $record->tested_at = now()->utc();
                    $record->tested_by = $actor->id;
                    $record->tested_version = $record->config_version;
                } else {
                    if (! $record->tested_at || $record->tested_version !== $record->config_version) {
                        throw ValidationException::withMessages(['workflow' => 'Den erfolgreichen Probeimport für diese Profilversion bestätigen.']);
                    }
                    if ($record->active) {
                        return $record;
                    }
                    $record->active = true;
                }
            }
            $this->persist($record, $actor, $event, $previouslyActive);

            return $record;
        });
    }

    private function requireReady(User $actor): void
    {
        OperationsAccess::authorize($actor, self::ABILITY);
        abort_unless(self::ready(), 503);
    }

    private function authorizeCompany(string $employer, User $actor, bool $lock = false): array
    {
        Validator::make(['employer_reference' => $employer], [
            'employer_reference' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_.-]+\z/'],
        ])->validate();

        $query = EmployeePayrollReference::where('employer_reference', $employer)->orderBy('id');
        if ($lock) {
            $query->lockForUpdate();
        }
        $refs = $query->get(['user_id', 'employer_reference', 'personnel_number']);
        $ids = app(PersonnelScopeService::class)->visibleUserIds($actor, self::ABILITY);
        if ($ids !== null) {
            abort_if($refs->isEmpty() || $refs->pluck('user_id')->diff($ids)->isNotEmpty(), 403);
        }
        $stored = LexwareProfile::where('employer_reference', $employer)->value('employer_reference');
        if (($stored !== null && $stored !== $employer) || ($stored === null && $refs->isNotEmpty() && ! $refs->contains('employer_reference', '===', $employer))) {
            throw ValidationException::withMessages(['workflow' => 'Abweichende Schreibweise der Arbeitgeberreferenz. Bitte die Lohnzuordnungen prüfen.']);
        }
        if ($refs->isEmpty() && $stored === null) {
            throw ValidationException::withMessages(['workflow' => 'Die Arbeitgeberreferenz muss zuerst in der Lohnzuordnung hinterlegt werden.']);
        }

        return $refs->toArray();
    }

    private function locked(string $employer, int $expectedRevision): ?LexwareProfile
    {
        $record = LexwareProfile::where('employer_reference', $employer)->lockForUpdate()->first();
        if (($record && $record->employer_reference !== $employer) || ($record?->revision ?? 0) !== $expectedRevision) {
            throw ValidationException::withMessages(['workflow' => 'Das Firmenprofil wurde inzwischen geändert. Bitte neu laden.']);
        }

        return $record;
    }

    private function normalize(array $data): array
    {
        $validated = Validator::make($data, [
            'company_name' => 'nullable|string|max:120',
            'import_profile' => 'nullable|string|max:120',
            'product_version' => 'nullable|string|max:80',
            'mappings' => 'nullable|array|max:500',
            'mappings.*' => 'array:source_code,target_code,quantity_kind,disjoint',
            'mappings.*.source_code' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_.-]{1,40}\z/'],
            'mappings.*.target_code' => ['nullable', 'string', 'regex:/\A[0-9]{1,4}\z/'],
            'mappings.*.quantity_kind' => 'nullable|in:base,supplement',
            'mappings.*.disjoint' => 'nullable|boolean',
        ])->validate();

        $mappings = [];
        foreach ($validated['mappings'] ?? [] as $row) {
            $target = $row['target_code'] ?? '';
            $mappings[] = [
                'source_code' => $row['source_code'] ?? '',
                'target_code' => $target === '' ? '' : str_pad($target, 4, '0', STR_PAD_LEFT),
                'quantity_kind' => $row['quantity_kind'] ?? '',
                'disjoint' => (bool) ($row['disjoint'] ?? false),
            ];
        }

        return [
            'company_name' => $this->optionalString($validated['company_name'] ?? null),
            'import_profile' => $this->optionalString($validated['import_profile'] ?? null),
            'product_version' => $this->optionalString($validated['product_version'] ?? null),
            'mappings' => $mappings,
        ];
    }

    private function optionalString(?string $value): ?string
    {
        $value = trim($value ?? '');

        return $value === '' ? null : $value;
    }

    private function clearTest(LexwareProfile $record): void
    {
        $record->tested_at = null;
        $record->tested_by = null;
        $record->tested_version = null;
    }

    private function persist(LexwareProfile $record, User $actor, string $event, bool $previouslyActive, array $context = []): void
    {
        $record->revision++;
        $record->save();
        app(OperationsAuditService::class)->record($record, $actor, 'lexware.profile.'.$event, $context + [
            'config_version' => $record->config_version,
            'previously_active' => $previouslyActive,
            'active' => $record->active,
            'tested_version' => $record->tested_version,
        ]);
    }
}
