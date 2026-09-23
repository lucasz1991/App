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
                if ($excel === $base) {
                    continue;
                }
                if ($local !== $base && $local !== $excel) {
                    $conflicts[$field][] = ['baseline' => $base, 'excel' => $excel, 'app' => $local, 'origin' => $appearance['origin'] ?? null];
                }
                $proposals[json_encode($excel, JSON_THROW_ON_ERROR)] = $excel;
            }
            if (count($proposals) > 1) {
                $conflicts[$field][] = ['reason' => 'appearances_disagree', 'values' => array_values($proposals)];
            }
            if (count($proposals) === 1 && ! isset($conflicts[$field])) {
                $merged[$field] = reset($proposals);
            }
        }

        return ['values' => $merged, 'conflicts' => $conflicts];
    }
}
