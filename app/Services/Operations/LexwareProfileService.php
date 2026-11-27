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
    private const PERSONNEL_NUMBER_PATTERN = '/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,18}\z/';

    public static function ready(): bool
    {
        return Schema::hasTable('lexware_profiles') && Schema::hasTable('employee_payroll_references') && Schema::hasTable('operation_audits');
    }

    public static function personnelKey(string $number): string
    {
        return ctype_digit($number) ? (ltrim($number, '0') ?: '0') : strtolower($number);
    }

    /** For downloads, the caller holds the same company lock as PayrollReferenceService::save(). */
    public static function referenceMatches(int $userId, string $employer, string $number): bool
    {
        if (! preg_match(self::PERSONNEL_NUMBER_PATTERN, $number) || ! Schema::hasTable('employee_payroll_references')) {
            return false;
        }

        $key = self::personnelKey($number);
        $refs = EmployeePayrollReference::where('employer_reference', $employer);
        if (ctype_digit($key)) {
            // References up to 64 characters may exist. Avoid numeric casts that overflow or round identifiers.
            $variants = [];
            for ($length = strlen($key); $length <= 64; $length++) {
                $variants[] = str_pad($key, $length, '0', STR_PAD_LEFT);
            }
            $refs->whereIn('personnel_number', $variants);
        } else {
            $refs->whereRaw('LOWER(personnel_number) = ?', [$key]);
        }
        $matches = $refs->limit(2)->get(['user_id', 'employer_reference', 'personnel_number']);
        $current = $matches->first();

        return $matches->count() === 1 && (int) $current->user_id === $userId
            && $current->employer_reference === $employer && $current->personnel_number === $number;
    }

    public function canManage(User $actor): bool
    {
        return $actor->status && $actor->can(self::ABILITY);
    }

    public function employers(User $actor): array
    {
        $actor = $this->requireReady($actor);
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
        $actor = $this->requireReady($actor);
        $this->authorizeCompany($employer, $actor);

        return LexwareProfile::where('employer_reference', $employer)->first();
    }

    public function history(string $employer, User $actor): array
    {
        return $this->profileHistory($this->get($employer, $actor));
    }

    public function overview(string $employer, User $actor): array
    {
        $actor = $this->requireReady($actor);
        $refs = $this->authorizeCompany($employer, $actor);
        $profile = LexwareProfile::where('employer_reference', $employer)->first();
        $referenceIssues = $this->referenceIssues($employer, $refs);
        $fieldIssues = $this->fieldIssues($profile?->only(self::FIELDS) ?? []);

        return [
            'profile' => $profile,
            'fieldIssues' => $fieldIssues,
            'referenceIssues' => $referenceIssues,
            'missing' => array_values(array_unique([...array_values($fieldIssues), ...$referenceIssues])),
            'history' => $this->profileHistory($profile),
        ];
    }

    private function profileHistory(?LexwareProfile $record): array
    {
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
        $actor = $this->requireReady($actor);

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

    public function fieldIssues(array $data): array
    {
        $reasons = [];
        if (blank($data['company_name'] ?? null)) {
            $reasons['company_name'] = 'Der Firmenname aus der Windows-Installation fehlt.';
        }
        if (blank($data['import_profile'] ?? null)) {
            $reasons['import_profile'] = 'Der Name des verwendeten Windows-Importprofils fehlt.';
        }
        $mappings = $data['mappings'] ?? [];
        if (! is_array($mappings) || ! $mappings) {
            $reasons['mappings'] = 'Mindestens eine Lohnart zuordnen.';

            return $reasons;
        }

        $sources = [];
        $targets = [];
        foreach (array_values($mappings) as $index => $mapping) {
            $source = is_string($mapping['source_code'] ?? null) ? trim($mapping['source_code']) : '';
            $target = is_string($mapping['target_code'] ?? null) ? trim($mapping['target_code']) : '';
            $kind = $mapping['quantity_kind'] ?? '';
            $field = 'mappings.'.$index.'.';
            if (! preg_match('/\A[A-Za-z0-9_.-]{1,40}\z/', $source)) {
                $reasons[$field.'source_code'] = 'RailTime-Lohncode aus der Vergütungsregel angeben.';
            } elseif (isset($sources[$source])) {
                $message = 'RailTime-Lohnart '.$source.' ist mehrfach zugeordnet.';
                $reasons['mappings.'.$sources[$source].'.source_code'] = $message;
                $reasons[$field.'source_code'] = $message;
            } else {
                $sources[$source] = $index;
            }
            if (! in_array($kind, ['base', 'supplement'], true)) {
                $reasons[$field.'quantity_kind'] = 'Eine Mengenart für diese Lohnart auswählen.';
            }
            if (! preg_match('/\A[0-9]{1,4}\z/', $target) || (int) $target === 0) {
                $reasons[$field.'target_code'] = 'Lexware-Lohnartnummer von 0001 bis 9999 angeben.';
            } else {
                $targets[str_pad($target, 4, '0', STR_PAD_LEFT)][$index] = $mapping;
            }
        }

        foreach ($targets as $target => $rows) {
            if (count($rows) < 2) {
                continue;
            }
            $kinds = array_column($rows, 'quantity_kind');
            $mixed = in_array('base', $kinds, true) && in_array('supplement', $kinds, true);
            foreach ($rows as $index => $row) {
                if ($mixed) {
                    $reasons['mappings.'.$index.'.quantity_kind'] = 'Lexware-Lohnart '.$target.' darf Grundstunden und Zuschlagsstunden nicht zusammenfassen.';
                }
                if (! in_array($row['disjoint'] ?? false, [true, 1, '1'], true)) {
                    $reasons['mappings.'.$index.'.disjoint'] = 'Für Lexware-Lohnart '.$target.' überschneidungsfreie Mengen bestätigen.';
                }
            }
        }

        return $reasons;
    }

    private function referenceIssues(string $employer, array $references): array
    {
        $issues = [];
        $refs = collect($references);
        if ($refs->contains(fn ($ref) => $ref['employer_reference'] !== $employer)) {
            $issues[] = 'Abweichende Schreibweisen der Arbeitgeberreferenz in den Lohnzuordnungen vereinheitlichen.';
        }
        if ($refs->isEmpty() || $refs->contains(fn ($ref) => ! preg_match(self::PERSONNEL_NUMBER_PATTERN, $ref['personnel_number']))) {
            $issues[] = 'Die Firma benötigt gültige Personalnummern aus der Lohnzuordnung (maximal 19 Zeichen).';
        }
        $numbers = $refs->map(fn ($ref) => self::personnelKey($ref['personnel_number']));
        if ($numbers->duplicatesStrict()->isNotEmpty()) {
            $issues[] = 'Mehrdeutige Personalnummern in der Zielfirma. Bitte führende Nullen und Groß-/Kleinschreibung in der Lohnzuordnung abgleichen.';
        }

        return $issues;
    }

    private function transition(string $employer, int $expectedRevision, User $actor, string $event): LexwareProfile
    {
        $actor = $this->requireReady($actor);

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
                $missing = array_values(array_unique([
                    ...array_values($this->fieldIssues($record->only(self::FIELDS))),
                    ...$this->referenceIssues($employer, $references),
                ]));
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

    private function requireReady(User $actor): User
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, self::ABILITY);
        abort_unless(self::ready(), 503);

        return $actor;
    }

    private function authorizeCompany(string $employer, User $actor, bool $lock = false): array
    {
        Validator::make(['employer_reference' => $employer], [
            'employer_reference' => ['required', 'string', 'max:64', 'regex:/\A[A-Za-z0-9_.-]+\z/'],
        ])->validate();

        $profile = LexwareProfile::where('employer_reference', $employer);
        $stored = $profile->when($lock, fn ($q) => $q->lockForUpdate())->value('employer_reference');
        $query = EmployeePayrollReference::where('employer_reference', $employer)->orderBy('id');
        if ($lock && $stored === null) {
            $query->lockForUpdate();
        }
        $refs = $query->get(['user_id', 'employer_reference', 'personnel_number']);
        $ids = app(PersonnelScopeService::class)->visibleUserIds($actor, self::ABILITY);
        if ($ids !== null) {
            abort_if($refs->isEmpty() || $refs->pluck('user_id')->diff($ids)->isNotEmpty(), 403);
        }
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
