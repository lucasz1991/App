<?php

namespace App\Services\Dropbox;

use Carbon\CarbonImmutable;

final class WeekFileMatcher
{
    public function match(string $name, string $rule = 'Aufträge KW {KW} [aktuell].xlsx'): ?array
    {
        if (preg_match('/(~\$|conflict|konflikt|backup|sicherung|\.bak)/iu', $name)) {
            return null;
        }
        $name = str_ireplace('Auftraege', 'Aufträge', $name);
        // User supplies a literal template, never executable regular expressions.
        $rule = str_ireplace('Auftraege', 'Aufträge', $rule);
        if ($rule === 'Aufträge KW {KW} [aktuell].xlsx') {
            $pattern = '/^Aufträge\s+KW\s*(?<week>0?[1-9]|[1-4][0-9]|5[0-3])(?:\s+(?<year>20[0-9]{2}))?(?:\s+aktuell)?(?:\s+(?<year_end>20[0-9]{2}))?\.xlsx$/iuD';
        } else {
            $quoted = preg_quote($rule, '/');
            $quoted = str_replace(' \\[aktuell\\]', '(?:\\s+aktuell)?', $quoted);
            $pattern = '/^'.str_replace(['\{KW\}', '\{YYYY\}', '\[aktuell\]'], ['(?<week>0?[1-9]|[1-4][0-9]|5[0-3])', '(?<year>20[0-9]{2})', '(?:aktuell)?'], $quoted).'$/iuD';
        }
        if (! preg_match($pattern, trim($name), $m)) {
            return null;
        }

        return ['week' => (int) $m['week'], 'year' => (int) (($m['year'] ?? '') ?: ($m['year_end'] ?? '')) ?: null];
    }

    public function week(string $date): string
    {
        return CarbonImmutable::parse($date, 'Europe/Berlin')->format('o-\WW');
    }

    public function filename(string $week, string $rule): string
    {
        $rule = str_ireplace('Auftraege', 'Aufträge', $rule);
        if ($rule === 'Aufträge KW {KW} [aktuell].xlsx') {
            return 'Aufträge KW '.substr($week, 6).' '.substr($week, 0, 4).'.xlsx';
        }
        if (! str_contains($rule, '{YYYY}')) {
            throw new \RuntimeException('filename_year_required');
        }
        $name = trim(preg_replace('/\s+/', ' ', str_replace(['{KW}', '{YYYY}', '[aktuell]'], [substr($week, 6), substr($week, 0, 4), ''], $rule)));
        $name = str_replace(' .xlsx', '.xlsx', $name);
        if (! $this->match($name, $rule) || preg_match('#[/\\\\]#', $name)) {
            throw new \RuntimeException('filename_rule_invalid');
        }

        return $name;
    }
}
