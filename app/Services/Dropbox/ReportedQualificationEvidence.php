<?php

namespace App\Services\Dropbox;

use App\Models\DropboxAppearance;
use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\DropboxRecord;
use App\Models\DropboxSource;
use App\Models\EmployeeCompetencyFact;
use App\Models\QualificationType;
use App\Models\User;
use Illuminate\Support\Collection;

final class ReportedQualificationEvidence
{
    public const KIND = 'reported_qualification';

    public const FIELD = 'reported_qualifications';

    public const MATRIX_KINDS = ['customer_authorization', 'document', 'introduction', 'local_knowledge'];

    private ?Collection $types = null;

    private array $checkedUnresolved = [];

    public function __construct(private EmployeeIdentityResolver $resolver) {}

    /** Old, already bound rows must try a newly resolvable person as well. */
    public function resolveStoredIdentity(DropboxConnection $connection, DropboxRecord $record, Collection $rows): ?DropboxIdentity
    {
        if (! in_array('competencies', $connection->option('domains'), true)) {
            return null;
        }
        $identityId = match ($record->model_type) {
            'DropboxIdentity' => $rows->contains(fn ($row) => ($row['entry']['locator']['kind'] ?? null) === 'employee'
                && isset($row['entry']['locator']['cells'][self::FIELD])) ? $record->model_id : null,
            'EmployeeCompetencyFact' => $rows->contains(fn ($row) => in_array($row['entry']['locator']['kind'] ?? null, self::MATRIX_KINDS, true))
                ? EmployeeCompetencyFact::whereKey($record->model_id)->value('identity_id') : null,
            default => null,
        };
        if (! $identityId) {
            return null;
        }
        $identity = DropboxIdentity::where('connection_id', $connection->id)->find($identityId);
        if ($identity && ! $connection->isLocalImport() && ! $identity->user_id && ! isset($this->checkedUnresolved[$identityId])) {
            $identity = $this->resolver->resolve($connection, $identity);
            if (! $identity->user_id) {
                $this->checkedUnresolved[$identityId] = true;
            }
        }

        return $identity;
    }

    public static function scopeFor(int $appearanceId): string
    {
        return 'dropbox-contact:'.$appearanceId;
    }

    /** A contact can become linked while another file's chunk is running. */
    public function outstandingLinkedContacts(DropboxConnection $connection, array $bound): array
    {
        if (! in_array('competencies', $connection->option('domains'), true)) {
            return [];
        }
        $recordByScope = [];
        foreach ($bound as $row) {
            $entry = $row['entry'];
            if ($entry['domain'] === 'contacts' && ($entry['locator']['kind'] ?? null) === 'employee'
                && trim($entry['values'][self::FIELD] ?? '') !== '' && isset($entry['locator']['cells'][self::FIELD])) {
                $recordByScope[self::scopeFor($row['appearance']->id)] = $row['appearance']->record_id;
            }
        }
        if (! $recordByScope) {
            return [];
        }
        $stored = EmployeeCompetencyFact::where('kind', self::KIND)->whereIn('scope', array_keys($recordByScope))->pluck('scope');
        $unrecorded = array_diff_key($recordByScope, array_flip($stored->all()));
        if (! $unrecorded) {
            return [];
        }

        return DropboxRecord::where('connection_id', $connection->id)->where('model_type', 'DropboxIdentity')
            ->whereIntegerInRaw('id', array_unique($unrecorded))
            ->whereIn('model_id', DropboxIdentity::where('connection_id', $connection->id)->where('kind', 'employee')->whereNotNull('user_id')->select('id'))
            ->pluck('id')->flip()->all();
    }

    /** A disputed phone number does not invalidate an independently corroborated qualification cell. */
    public function recordWithDisputedPhone(DropboxIdentity $identity, DropboxSource $source, Collection $rows, array $accepted, string $revision): void
    {
        $email = trim($accepted['contact_email'] ?? '');
        if ($email === '' || ! $identity->user_id || ! User::whereKey($identity->user_id)->where('role', 'staff')
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])->exists()) {
            return;
        }
        foreach ($rows as $row) {
            $entry = $row['entry'];
            $values = $entry['values'];
            if (trim($values[self::FIELD] ?? '') === '' || ($values['contact_email'] ?? '') !== $email) {
                continue;
            }
            $nameFields = trim($values['name'] ?? '') !== '' ? ['name'] : ['first_name', 'last_name'];
            foreach ($nameFields as $field) {
                if (trim($values[$field] ?? '') === '' || ($accepted[$field] ?? null) !== $values[$field]) {
                    continue 2;
                }
            }
            $this->record($identity, $source, $row['appearance'], $entry, $accepted, $revision);
        }
    }

    /** Keep the source statement and its exact cell distinct from approved personnel proof. */
    public function record(DropboxIdentity $identity, DropboxSource $source, DropboxAppearance $appearance, array $entry, array $accepted, ?string $sourceRevision = null): void
    {
        $value = $entry['values'][self::FIELD] ?? null;
        $cell = $entry['locator']['cells'][self::FIELD] ?? null;
        if (($entry['locator']['kind'] ?? null) !== 'employee' || ! is_string($value) || ! is_string($cell)
            || ($accepted[self::FIELD] ?? null) !== $value || $identity->kind !== 'employee' || ! $identity->user_id
            || ! User::whereKey($identity->user_id)->where('role', 'staff')->exists()) {
            return;
        }
        $fact = EmployeeCompetencyFact::where('kind', self::KIND)->where('scope', self::scopeFor($appearance->id))->lockForUpdate()->first();
        if (! $fact && $value === '') {
            return;
        }
        $origin = ['source_id' => $source->id, 'file_id' => $source->file_id, 'appearance_id' => $appearance->id, 'sheet' => $entry['sheet'], 'cell' => $cell];
        $previous = $fact?->value ?? [];
        $previousOrigin = $previous['origin'] ?? [];
        $sameIdentity = (int) $fact?->identity_id === $identity->id;
        $sameStatement = $sameIdentity && ($previous['value'] ?? null) === $value
            && $this->sameProperties(array_diff_key($previousOrigin, ['revision' => true]), $origin);
        $readRevision = $sourceRevision ?? $appearance->seen_rev;
        $origin['revision'] = $sameStatement ? ($previousOrigin['revision'] ?? $readRevision) : $readRevision;
        $evidence = ['value' => $value, 'color' => null, 'status' => $value === '' ? 'withdrawn' : 'reported',
            'qualification_type_ids' => $this->typeIds($value), 'origin' => $origin];
        if ($sameIdentity && $this->sameProperties($previousOrigin, $origin)
            && $this->sameProperties(array_diff_key($previous, ['origin' => true]), array_diff_key($evidence, ['origin' => true]))) {
            return;
        }
        SyncContext::import(function () use ($fact, $identity, $appearance, $evidence) {
            if ($fact) {
                $fact->forceFill(['identity_id' => $identity->id, 'value' => $evidence, 'revision' => $fact->revision + 1])->save();

                return;
            }
            EmployeeCompetencyFact::create(['identity_id' => $identity->id, 'kind' => self::KIND, 'name' => 'Excel-Angabe zu Position oder Qualifikation',
                'scope' => self::scopeFor($appearance->id), 'value' => $evidence]);
        });
    }

    private function sameProperties(array $left, array $right): bool
    {
        // SQL JSON objects do not preserve insertion order. Value types still matter.
        ksort($left);
        ksort($right);

        return $left === $right;
    }

    private function typeIds(string $value): array
    {
        if ($value === '') {
            return [];
        }
        $this->types ??= QualificationType::where('is_active', true)->get(['id', 'name'])->groupBy(fn ($type) => WorkbookReader::normalize($type->name));
        $exact = $this->types->get(WorkbookReader::normalize($value));
        if ($exact) {
            return $exact->count() === 1 ? [(int) $exact->sole()->id] : [];
        }
        $types = [];
        foreach (preg_split('#[,;\r\n/]+#u', $value) as $term) {
            $matches = $this->types->get(WorkbookReader::normalize($term));
            if ($matches?->count() === 1) {
                $id = (int) $matches->sole()->id;
                $types[$id] = $id;
            }
        }
        sort($types);

        return $types;
    }
}
