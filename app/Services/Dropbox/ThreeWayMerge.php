<?php

namespace App\Services\Dropbox;

final class ThreeWayMerge
{
    /** Merge every appearance independently against its own last common baseline. */
    public function merge(array $app, array $appearances): array
    {
        $merged = $app;
        $conflicts = [];
        foreach (array_unique(array_merge(array_keys($app), ...array_map(fn ($a) => array_keys($a['excel']), $appearances))) as $field) {
            $proposals = [];
            foreach ($appearances as $appearance) {
                if (! array_key_exists($field, $appearance['excel'])) {
                    continue;
                }
                $base = $appearance['baseline'][$field] ?? null;
                $excel = $appearance['excel'][$field];
                $local = $app[$field] ?? null;
                $excelKey = $this->comparisonValue($field, $excel);
                $baseKey = $this->comparisonValue($field, $base);
                $localKey = $this->comparisonValue($field, $local);
                if ($excelKey === $baseKey) {
                    continue;
                }
                if ($localKey !== $baseKey && $localKey !== $excelKey) {
                    $conflicts[$field][] = ['baseline' => $base, 'excel' => $excel, 'app' => $local, 'origin' => $appearance['origin'] ?? null];
                }
                $proposalKey = json_encode($excelKey, JSON_THROW_ON_ERROR);
                if (! array_key_exists($proposalKey, $proposals)) {
                    $proposals[$proposalKey] = $excel;
                }
            }
            if (count($proposals) > 1) {
                $conflicts[$field][] = ['reason' => 'appearances_disagree', 'values' => array_values($proposals)];
            }
            if (count($proposals) === 1 && ! isset($conflicts[$field])) {
                $proposal = reset($proposals);
                if (! array_key_exists($field, $app) || $this->comparisonValue($field, $app[$field] ?? null) !== $this->comparisonValue($field, $proposal)) {
                    $merged[$field] = $proposal;
                }
            }
        }

        return ['values' => $merged, 'conflicts' => $conflicts];
    }

    private function comparisonValue(string $field, mixed $value): mixed
    {
        if (in_array($field, ['phone', 'mobile'], true) && is_string($value)) {
            return preg_replace('/\s+/u', '', $value) ?? $value;
        }

        return $value;
    }
}
