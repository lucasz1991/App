<?php

namespace App\Services\Dropbox;

final class PlanningTimeCopyEvidence
{
    private const ANCHORS = ['date', 'employee', 'train_reference', 'location', 'customer', 'role'];

    public function disagreements(array $entries): array
    {
        $index = $recovered = [];
        foreach ($entries as $entry) {
            $key = $this->anchor($entry);
            if ($key === null) {
                continue;
            }
            $master = (int) ($entry['locator']['master'] ?? false);
            $index[$key][$master][] = $entry;
            if ($entry['locator']['recovered_plan_time'] ?? false) {
                $recovered[] = [$key, $master, $entry];
            }
        }
        $issues = [];
        foreach ($recovered as [$key, $master, $entry]) {
            $counterparts = array_filter($index[$key][1 - $master] ?? [], fn ($other) => ! $this->differentReference($entry, $other));
            $values = $entry['values'];
            $overlaps = [];
            foreach ($counterparts as $other) {
                $comparison = $other['values'];
                if ($values['starts'] === $comparison['starts'] && $values['ends'] === $comparison['ends']) {
                    // The normal row-matching rules can use an exact interval.
                    continue 2;
                }
                if ($this->overlaps($values, $comparison)) {
                    $overlaps[] = ['sheet' => $other['sheet'], 'row' => $other['locator']['row'], 'cell' => $other['locator']['cells']['time'] ?? null,
                        'starts' => $comparison['starts'], 'ends' => $comparison['ends']];
                }
            }
            if ($overlaps) {
                $issues[$entry['sheet'].'!'.$entry['slot']] = ['reason' => 'planning_copy_time_disagreement',
                    'sheet' => $entry['sheet'], 'row' => $entry['locator']['row'], 'cell' => $entry['locator']['cells']['time'] ?? null,
                    'date' => $values['date'], 'starts' => $values['starts'], 'ends' => $values['ends'], 'counterparts' => $overlaps, 'retained' => true];
            }
        }

        return $issues;
    }

    private function anchor(array $entry): ?string
    {
        if ($entry['domain'] !== 'planning' || empty($entry['values']['starts']) || empty($entry['values']['ends'])) {
            return null;
        }
        $values = [];
        foreach (self::ANCHORS as $field) {
            $value = WorkbookReader::normalize($entry['values'][$field] ?? '');
            if ($value === '') {
                return null;
            }
            $values[] = $value;
        }

        return json_encode($values, JSON_THROW_ON_ERROR);
    }

    private function differentReference(array $entry, array $other): bool
    {
        $reference = WorkbookReader::normalize($entry['values']['service_reference'] ?? '');
        $otherReference = WorkbookReader::normalize($other['values']['service_reference'] ?? '');

        return $reference !== '' && $otherReference !== '' && $reference !== $otherReference;
    }

    private function overlaps(array $left, array $right): bool
    {
        [$leftStart, $leftEnd] = $this->interval($left);
        [$rightStart, $rightEnd] = $this->interval($right);

        return $leftStart < $rightEnd && $rightStart < $leftEnd;
    }

    private function interval(array $values): array
    {
        $minutes = fn (string $clock): int => ((int) substr($clock, 0, 2)) * 60 + (int) substr($clock, 3, 2);
        $start = $minutes($values['starts']);
        $end = $minutes($values['ends']);

        return [$start, $end > $start ? $end : $end + 1440];
    }
}
