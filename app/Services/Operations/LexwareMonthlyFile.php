<?php

namespace App\Services\Operations;

use App\Models\OperationsMonthClosing;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Validation\ValidationException;

final class LexwareMonthlyFile
{
    public static function sample(): string
    {
        $seconds = 4 * 3600 + 30 * 60 + 3 * 3600 + 30 * 60;

        return self::encode('2026-03', '00017', [['target_code' => '0001', 'value' => self::hours($seconds)]]);
    }

    public static function build(OperationsMonthClosing $closing, array $snapshot, array $mappings): array
    {
        self::check(($snapshot['schema'] ?? '') === 'railtime-payroll-preparation-v2', 'Dieser Abschluss enthält keinen vollständigen Nachweis der Mengenarten. Monatsabschluss für Lexware erneut prüfen und freigeben.');
        self::check(($snapshot['complete'] ?? null) === true && ($snapshot['month'] ?? null) === $closing->month && (int) ($snapshot['user_id'] ?? 0) === (int) $closing->user_id, 'Der freigegebene Monat oder die Mitarbeiterzuordnung ist unvollständig.');
        $personnel = $snapshot['payroll_reference']['personnel_number'] ?? null;
        self::check(is_string($personnel) && preg_match('/^[A-Za-z0-9][A-Za-z0-9_.-]{0,18}$/D', $personnel), 'Im Abschluss fehlt eine für Lexware geeignete Personalnummer (1–19 Zeichen).');
        $zone = $snapshot['timezone'] ?? '';
        self::check(is_string($zone) && $zone === $closing->timezone && in_array($zone, timezone_identifiers_list(), true), 'Die Zeitzone des Monatsabschlusses ist unvollständig.');
        self::check((bool) preg_match('/^\d{4}-(?:0[1-9]|1[0-2])$/D', $closing->month), 'Ungültiger Abrechnungsmonat.');
        $monthStart = new DateTimeImmutable($closing->month.'-01', new DateTimeZone($zone));
        $monthEnd = $monthStart->modify('+1 month');
        $bySource = [];
        foreach ($mappings as $mapping) {
            $source = $mapping['source_code'] ?? '';
            self::check(is_string($source) && $source !== '' && ! isset($bySource[$source]), 'Die Zuordnung einer Quelllohnart ist mehrdeutig.');
            $bySource[$source] = $mapping;
        }

        $groups = [];
        $intervals = [];
        $skipped = 0;
        self::check(is_array($snapshot['entries'] ?? null), 'Im Abschluss fehlen die Zeitpositionen.');
        foreach ($snapshot['entries'] as $entry) {
            $id = $entry['id'] ?? null;
            self::check(is_int($id) && $id > 0 && ! isset($intervals[$id]) && ($entry['status'] ?? null) === 'approved', 'Die Zeitpositionen sind nicht eindeutig freigegeben.');
            $start = max(self::timestamp($entry['starts_at'] ?? null), $monthStart->getTimestamp());
            $end = min(self::timestamp($entry['ends_at'] ?? null), $monthEnd->getTimestamp());
            self::check($start < $end, 'Ein Zeitintervall liegt außerhalb des Monats.');
            $intervals[$id] = [$start, $end];
            $coverage = self::addEntry($entry['quantities'] ?? null, $bySource, $groups, $skipped, $end - $start);
            self::check($coverage === $end - $start, 'Die Grundmengen decken das Zeitintervall nicht eindeutig ab. Überschneidungen oder fehlende Stunden prüfen.');
        }
        usort($intervals, static fn (array $a, array $b) => $a[0] <=> $b[0]);
        $previousEnd = null;
        foreach ($intervals as [$start, $end]) {
            self::check($previousEnd === null || $previousEnd <= $start, 'Die freigegebenen Arbeitszeitintervalle überschneiden sich.');
            $previousEnd = $end;
        }
        self::check($groups !== [], 'Der Abschluss enthält keine exportierbare positive Stundenmenge.');
        ksort($groups, SORT_STRING);
        $rows = [];
        foreach ($groups as $group) {
            $group['value'] = self::hours($group['raw_seconds']);
            $group['source_codes'] = array_keys($group['sources']);
            $group['source_codes'] = array_map('strval', $group['source_codes']);
            sort($group['source_codes'], SORT_STRING);
            self::check(count($group['sources']) === 1 || ! in_array(false, $group['sources'], true), 'Mehrere Quelllohnarten teilen ein Ziel. Die überschneidungsfreie Zuordnung ist nicht für alle Quellen bestätigt.');
            unset($group['sources']);
            $rows[] = $group;
        }

        return ['rows' => $rows, 'skipped_zero' => $skipped, 'content' => self::encode($closing->month, $personnel, $rows)];
    }

    private static function addEntry(mixed $quantities, array $mappings, array &$groups, int &$skipped, int $elapsed): int
    {
        self::check(is_array($quantities) && $quantities !== [], 'Für einen Zeiteintrag fehlen die bestätigten Vergütungsmengen.');
        $coverage = 0;
        $rules = [];
        $supplements = [];
        foreach ($quantities as $q) {
            $rule = $q['rule_id'] ?? null;
            $activity = $q['activity'] ?? '';
            $raw = $q['raw_seconds'] ?? null;
            $quantity = $q['quantity_seconds'] ?? null;
            $additive = $q['additive'] ?? null;
            self::check(is_int($rule) && $rule > 0 && ! isset($rules[$rule]) && is_int($q['rule_revision'] ?? null) && $q['rule_revision'] > 0, 'Eine Regelrevision fehlt oder ist im Zeiteintrag mehrfach enthalten.');
            self::check(is_bool($additive) && isset(WorkTimeActivityService::KINDS[$activity]) && is_int($raw) && $raw > 0 && $raw <= $elapsed && is_int($quantity) && $quantity >= 0, 'Der Zeitnachweis für eine Mengenposition ist unvollständig.');
            $rules[$rule] = true;
            if (! $additive) {
                $coverage += $raw;
            }
            if ($activity === 'break' && ! $additive && ($q['multiplier_bps'] ?? null) === 0 && $quantity === 0) {
                $skipped++;
                continue;
            }
            $code = $q['wage_code'] ?? '';
            self::check(is_string($code) && $code !== '' && isset($mappings[$code]), 'Für eine Quelllohnart fehlt die Zuordnung im freigegebenen Profil: '.(is_string($code) ? $code : 'unbekannt').'.');
            self::check(($q['multiplier_bps'] ?? null) === 10000 && ($q['rounding_minutes'] ?? null) === 0 && ($q['rounding_mode'] ?? null) === 'none' && $raw === $quantity, 'Die Lohnart '.$code.' enthält eine gewichtete oder vorgerundete Menge. Für Lexware sind ungewichtete Stunden erforderlich.');
            $kind = $additive ? 'supplement' : 'base';
            $mapping = $mappings[$code];
            $target = $mapping['target_code'] ?? '';
            self::check(($mapping['quantity_kind'] ?? null) === $kind && is_string($target) && preg_match('/^(?!0000)\d{4}$/D', $target), 'Mengenart oder Ziellohnart passt nicht zur freigegebenen Regel für '.$code.'.');
            $groups[$target] ??= ['target_code' => $target, 'quantity_kind' => $kind, 'sources' => [], 'raw_seconds' => 0];
            self::check($groups[$target]['quantity_kind'] === $kind, 'Grundstunden und Zuschlagsstunden dürfen keine gemeinsame Ziellohnart haben.');
            if ($additive) {
                // Multiple rules for the same source within one activity have no independent non-overlap proof.
                self::check(! isset($supplements[$target][$activity][$code]), 'Mehrere Zuschlagsregeln überschneiden sich möglicherweise bei der Lohnart '.$code.'.');
                $supplements[$target][$activity][$code] = $raw;
                self::check(array_sum($supplements[$target][$activity]) <= $elapsed, 'Die Zuschlagsbasis übersteigt die Dauer des Zeiteintrags.');
            }
            $groups[$target]['sources'][$code] = ($mapping['disjoint'] ?? false) === true;
            $groups[$target]['raw_seconds'] += $raw;
        }

        return $coverage;
    }

    private static function timestamp(mixed $value): int
    {
        self::check(is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $value), 'Im Abschluss fehlt ein eindeutiger Zeitstempel.');
        $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $value);
        self::check($date !== false && ! DateTimeImmutable::getLastErrors(), 'Im Abschluss ist ein Zeitstempel ungültig.');

        return $date->getTimestamp();
    }

    private static function hours(int $seconds): string
    {
        $hundredths = intdiv($seconds + 18, 36);
        self::check($hundredths > 0, 'Eine positive Stundenmenge würde zu 0,00 gerundet. Position vor der Übergabe klären.');

        return sprintf('%d,%02d', intdiv($hundredths, 100), $hundredths % 100);
    }

    private static function encode(string $month, string $personnel, array $rows): string
    {
        [$year, $monthNumber] = explode('-', $month);
        $lines = ['Jahr;Monat;Personalnummer;Lohnartnummer;Wert'];
        foreach ($rows as $row) {
            $lines[] = implode(';', [$year, $monthNumber, $personnel, $row['target_code'], $row['value']]);
        }

        return implode("\r\n", $lines)."\r\n";
    }

    private static function check(bool $valid, string $reason): void
    {
        if (! $valid) {
            throw ValidationException::withMessages(['lexware' => $reason]);
        }
    }
}
