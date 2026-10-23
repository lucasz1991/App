<?php

namespace App\Services\Dropbox;

use App\Models\DropboxConnection;
use App\Models\DropboxIdentity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/** Adds read-only provenance; callers must not treat it as a changed Excel MA cell. */
class PlanningEmployeeEvidence
{
    public const PERSONAL_SHEET = 'personal_sheet';

    public const MATCHING_PERSONAL_ROW = 'matching_personal_row';

    /**
     * Only locator.employee_evidence is derived. Values, fingerprints and other domains are untouched.
     * The consumer must independently protect an explicitly cleared existing employee baseline.
     *
     * @return array Original parsed workbook with optional {label,user_id,basis,sheet,slot} provenance.
     */
    public function annotate(DropboxConnection $connection, array $parsed): array
    {
        if (! is_array($parsed['rows'] ?? null)) {
            return $parsed;
        }
        [$users, $names] = $this->people($connection);
        $rows = $parsed['rows'];
        $sheets = [];
        $masters = [];
        $blankMasters = [];
        $copies = [];
        $events = [];
        $explicit = [];
        foreach ($rows as $index => &$row) {
            if (($row['domain'] ?? null) !== 'planning') {
                continue;
            }
            // Repeated annotation must not preserve stale evidence after a name or status changed.
            unset($row['locator']['employee_evidence']);
            $sheet = (string) ($row['sheet'] ?? '');
            $isMaster = $row['locator']['master'] ?? $parsed['sheets'][$sheet]['master'] ?? null;
            if (! is_bool($isMaster)) {
                continue;
            }
            $label = trim((string) ($row['values']['employee'] ?? ''));
            $explicit[$index] = $label !== '' ? $this->person($label, $names, $users) : null;
            if ($isMaster && $label === '') {
                $blankMasters[$this->rawEventKey($row['values'] ?? [])] = true;
            }
            if (! $isMaster) {
                $sheets[$sheet][] = $index;
            }
            $events[$index] = $this->event($row['values'] ?? [], (string) $connection->option('timezone'));
            if ($events[$index]) {
                $key = $events[$index]['key'];
                if ($isMaster) {
                    $masters[$key][] = $index;
                } else {
                    $copies[$key][] = $index;
                }
            }
        }
        unset($row);

        $candidates = [];
        foreach ($sheets as $sheet => $indices) {
            $sheet = (string) $sheet;
            $owner = $this->person($sheet, $names, $users);
            $named = [];
            $conflicting = false;
            foreach ($indices as $index) {
                if (trim((string) ($rows[$index]['values']['employee'] ?? '')) === '') {
                    continue;
                }
                $person = $explicit[$index];
                if (! $person || ($owner && (int) $owner->id !== (int) $person->id)) {
                    $conflicting = true;
                    break;
                }
                $named[$person->id] = true;
            }
            if ($conflicting || count($named) > 1) {
                continue;
            }
            foreach ($indices as $index) {
                $row = $rows[$index];
                $label = trim((string) ($row['values']['employee'] ?? ''));
                $person = $label !== '' ? $explicit[$index] : $owner;
                if (! $person || ! $this->eligible($person, $row['values'] ?? [], $connection)) {
                    continue;
                }
                $evidence = ['label' => $label !== '' ? $label : $sheet, 'user_id' => (int) $person->id,
                    'basis' => self::PERSONAL_SHEET, 'sheet' => $sheet, 'slot' => (string) ($row['slot'] ?? '')];
                if ($label === '') {
                    $rows[$index]['locator']['employee_evidence'] = $evidence;
                }
                $candidates[$index] = $evidence;
            }
        }

        $propagated = [];
        foreach ($masters as $key => $indices) {
            // Count every matching row, not just resolved people, so duplicate/crew rows stay separate.
            if (count($indices) !== 1 || count($copies[$key] ?? []) !== 1) {
                continue;
            }
            $masterIndex = $indices[0];
            $copyIndex = $copies[$key][0];
            $master = $rows[$masterIndex];
            $copy = $rows[$copyIndex];
            if (trim((string) ($master['values']['employee'] ?? '')) !== '' || ! isset($candidates[$copyIndex])
                || ! $this->sameStatus($master['values'], $copy['values'])
                || ! $this->compatibleReference($master['values'], $copy['values'])) {
                continue;
            }
            $rows[$masterIndex]['locator']['employee_evidence'] = [
                ...$candidates[$copyIndex], 'basis' => self::MATCHING_PERSONAL_ROW,
            ];
            $propagated[$copyIndex] = true;
        }
        foreach ($candidates as $index => $_) {
            if (trim((string) ($rows[$index]['values']['employee'] ?? '')) === ''
                && isset($blankMasters[$this->rawEventKey($rows[$index]['values'] ?? [])])
                && ! isset($propagated[$index])) {
                // Deriving only the personal side would split an existing blank master/copy pair.
                unset($rows[$index]['locator']['employee_evidence']);
            }
        }

        $parsed['rows'] = $rows;

        return $parsed;
    }

    private function people(DropboxConnection $connection): array
    {
        $users = User::where('role', 'staff')->with('profile')->get()->keyBy('id');
        $names = [];
        foreach ($users as $user) {
            foreach ($this->keys((string) $user->name, true) as $key) {
                $names[$key][$user->id] = true;
            }
            $first = trim((string) $user->profile?->first_name);
            $last = trim((string) $user->profile?->last_name);
            if ($first !== '' && $last !== '') {
                foreach ([$first.' '.$last, $last.' '.$first] as $label) {
                    foreach ($this->keys($label, true) as $key) {
                        $names[$key][$user->id] = true;
                    }
                }
            }
        }
        $blocked = [];
        foreach (DropboxIdentity::where('connection_id', $connection->id)->get() as $identity) {
            $labels = [$identity->alias, $identity->details['display_name'] ?? ''];
            foreach ($labels as $label) {
                foreach ($this->keys((string) $label) as $key) {
                    if ($identity->kind === 'employee' && $identity->user_id && $users->has($identity->user_id)) {
                        $names[$key][$identity->user_id] = true;
                    } elseif ($identity->kind === 'provider' || ($identity->kind === 'employee' && $identity->user_id)) {
                        $blocked[$key] = true;
                    }
                }
            }
        }
        foreach ($blocked as $key => $_) {
            unset($names[$key]);
        }

        return [$users, $names];
    }

    private function person(string $label, array $names, Collection $users): ?User
    {
        $matches = [];
        foreach ($this->keys($label) as $key) {
            $matches += $names[$key] ?? [];
        }

        return count($matches) === 1 ? $users->get(array_key_first($matches)) : null;
    }

    private function keys(string $label, bool $fullName = false): array
    {
        if (preg_match('#[/&+;|\r\n@]|\b(?:und|oder|and|gmbh|gbr|ug|kg)\b#iu', $label)) {
            return [];
        }
        $key = WorkbookReader::normalize(Str::ascii(preg_replace('/[,.]+/u', ' ', $label)));
        if ($key === '' || ($fullName && str_word_count($key) < 2)) {
            return [];
        }
        $parts = explode(' ', $key);

        return count($parts) === 2 ? array_values(array_unique([$key, implode(' ', array_reverse($parts))])) : [$key];
    }

    private function eligible(User $user, array $values, DropboxConnection $connection): bool
    {
        if ($user->status) {
            return true;
        }
        $end = $this->end($values, (string) $connection->option('timezone'));

        return $end && $end->lessThanOrEqualTo(CarbonImmutable::now($end->timezone));
    }

    private function event(array $values, string $timezone): ?array
    {
        $fields = ['date', 'starts', 'ends', 'customer', 'location', 'role', 'train_reference'];
        $key = [];
        foreach ($fields as $field) {
            if (! is_string($values[$field] ?? null) || trim($values[$field]) === '') {
                return null;
            }
            $key[] = $values[$field];
        }
        if (! $this->end($values, $timezone)) {
            return null;
        }

        return ['key' => json_encode($key, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)];
    }

    private function rawEventKey(array $values): string
    {
        return json_encode(array_map(fn ($field) => (string) ($values[$field] ?? ''),
            ['date', 'starts', 'ends', 'customer', 'location', 'role', 'train_reference']), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    private function end(array $values, string $timezone): ?CarbonImmutable
    {
        $date = $values['date'] ?? '';
        $start = $values['starts'] ?? '';
        $end = $values['ends'] ?? '';
        if (! is_string($date) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date)
            || ! is_string($start) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $start)
            || ! is_string($end) || ! preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/D', $end)) {
            return null;
        }
        try {
            $from = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$start, $timezone);
            $until = CarbonImmutable::createFromFormat('!Y-m-d H:i', $date.' '.$end, $timezone);
            if (! $from || ! $until || $from->format('Y-m-d H:i') !== $date.' '.$start || $until->format('Y-m-d H:i') !== $date.' '.$end) {
                return null;
            }

            return $until->lessThanOrEqualTo($from) ? $until->addDay() : $until;
        } catch (\Throwable) {
            return null;
        }
    }

    private function sameStatus(array $first, array $second): bool
    {
        foreach (['cancelled', 'draft'] as $field) {
            if (! is_bool($first[$field] ?? null)) {
                return false;
            }
            // A personal sheet without this column cannot contradict or revoke the master's status.
            if (! array_key_exists($field, $second)) {
                continue;
            }
            if (! is_bool($second[$field]) || $first[$field] !== $second[$field]) {
                return false;
            }
        }

        return true;
    }

    private function compatibleReference(array $first, array $second): bool
    {
        $first = trim((string) ($first['service_reference'] ?? ''));
        $second = trim((string) ($second['service_reference'] ?? ''));

        return $first === '' || $second === '' || $first === $second;
    }
}
