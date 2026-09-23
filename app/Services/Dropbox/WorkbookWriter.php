<?php

namespace App\Services\Dropbox;

use PhpOffice\PhpSpreadsheet\Shared\Date;

class WorkbookWriter
{
    public function cells(array $entry, array $values): array
    {
        if ($entry['domain'] === 'competencies') {
            return [$entry['locator']['cell'] => ['value' => $values['value'], 'color' => $values['color'] ?? null]];
        }
        $cells = [];
        $loc = $entry['locator'];
        foreach ($loc['cells'] as $field => $address) {
            if (isset($entry['fingerprint'])) {
                $dependencies = match ($field) {
                    'time' => ['starts', 'ends', 'actual_end'], 'notes' => ['notes', 'draft'], 'cancellation' => ['cancellation', 'cancelled'], default => [$field]
                };
                $changed = false;
                foreach ($dependencies as $dependency) {
                    if (($values[$dependency] ?? null) !== ($entry['values'][$dependency] ?? null)) {
                        $changed = true;
                        break;
                    }
                }
                if (! $changed) {
                    continue;
                }
            }
            $value = $values[$field] ?? '';
            if ($field === 'time') {
                $value = ($values['starts'] ?? '').'–'.($values['ends'] ?? '');
                if ($values['actual_end'] ?? null) {
                    $value .= '/–'.$values['actual_end'];
                }
            }
            if ($field === 'notes') {
                $value = trim(str_replace('[ENTWURF]', '', $value));
                if ($values['draft'] ?? false) {
                    $value = trim('[ENTWURF] '.$value);
                }
            }
            if ($field === 'cancellation' && ($values['cancelled'] ?? false) && ! preg_match('/storno|storniert/iu', $value)) {
                $value = trim('Storno '.$value);
            }
            if (in_array($field, ['date', 'ordered_at', 'birth_date'], true) && preg_match('/^\d{4}-\d{2}-\d{2}$/D', (string) $value)) {
                $value = Date::PHPToExcel(new \DateTimeImmutable($value));
            }
            preg_match('/^[A-Z]+/', $address, $m);
            $cells[$address] = ['value' => $value];
            if (isset($loc['styles'][$m[0]])) {
                $cells[$address]['style'] = $loc['styles'][$m[0]];
            }
        }

        return $cells;
    }

    public function write(string $bytes, array $changes, string $profile): string
    {
        $package = new WorkbookPackage($bytes);
        $patch = [];
        foreach ($changes as $change) {
            $entry = $change['entry'];
            $cells = $this->cells($entry, $change['values']);
            foreach ($cells as $address => $value) {
                if (isset($patch[$entry['sheet']][$address]) && $patch[$entry['sheet']][$address] !== $value) {
                    throw new \RuntimeException('contradictory_cell_changes');
                }
                $patch[$entry['sheet']][$address] = $value;
            }
        }
        $package->patch($patch);
        $output = $package->bytes();
        $parsed = app(WorkbookReader::class)->read($output, $profile);
        $index = [];
        foreach ($parsed['rows'] as $row) {
            $index[$row['sheet'].'!'.$row['slot']] = $row;
        }
        foreach ($changes as $change) {
            $key = $change['entry']['sheet'].'!'.$change['entry']['slot'];
            if (! isset($index[$key])) {
                throw new \RuntimeException('written_row_unreadable');
            }
            foreach ($change['values'] as $field => $value) {
                if (array_key_exists($field, $index[$key]['values']) && $index[$key]['values'][$field] !== $value) {
                    throw new \RuntimeException('written_value_mismatch:'.$field);
                }
            }
        }

        return $output;
    }
}
