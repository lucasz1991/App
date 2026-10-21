<?php

namespace App\Services\Dropbox;

/** A newly discovered copy has no permission to overwrite an existing value. */
final class InitialCopyBaseline
{
    public function forCopy(array $app, array $excel, iterable $previous, string $domain): array
    {
        $baselines = [];
        foreach ($previous as $appearance) {
            $baselines[] = $appearance->baseline;
        }
        $baseline = [];
        foreach ($excel as $field => $value) {
            $local = $app[$field] ?? null;
            if ($value === $local || ($domain === 'planning' && $field === 'draft')) {
                $baseline[$field] = $value;

                continue;
            }
            // A known old value is a stale copy, not a new edit. The App change
            // must flow back through the normal three-way comparison.
            $known = false;
            foreach ($baselines as $previousValues) {
                if (array_key_exists($field, $previousValues) && $previousValues[$field] === $value) {
                    $known = true;
                    break;
                }
            }
            if ($known) {
                $baseline[$field] = $value;
            } elseif (! array_key_exists($field, $app)) {
                $baseline[$field] = null;
            } elseif ($local === '' || $local === null) {
                $baseline[$field] = $local;
                foreach ($baselines as $previousValues) {
                    if (isset($previousValues[$field]) && $previousValues[$field] !== '') {
                        // Both a deletion in the App and a different new value
                        // in this copy changed the previously populated field.
                        $baseline[$field] = $previousValues[$field];
                        break;
                    }
                }
            } elseif ($value === '' || $value === null) {
                // An empty cell in a new copy cannot erase populated data.
                $baseline[$field] = $value;
            } else {
                // Two populated, different values without common evidence need
                // a field decision, not another entity or a guessed winner.
                $baseline[$field] = null;
            }
        }

        return $baseline;
    }
}
