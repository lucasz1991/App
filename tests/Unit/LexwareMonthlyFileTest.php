<?php

namespace Tests\Unit;

use App\Models\OperationsMonthClosing;
use App\Services\Operations\LexwareMonthlyFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class LexwareMonthlyFileTest extends TestCase
{
    private function quantity(string $activity, int $rule, int $seconds, int $factor = 10000, bool $additive = false): array
    {
        return [
            'activity' => $activity, 'rule_id' => $rule, 'rule_revision' => 1, 'wage_code' => 'CODE'.$rule, 'additive' => $additive,
            'multiplier_bps' => $factor, 'rounding_minutes' => 0, 'rounding_mode' => 'none',
            'raw_seconds' => $seconds, 'quantity_seconds' => intdiv($seconds * $factor, 10000),
        ];
    }

    private function entry(int $id, string $start, string $end, array $quantities): array
    {
        return ['id' => $id, 'status' => 'approved', 'starts_at' => $start, 'ends_at' => $end, 'quantities' => $quantities];
    }

    private function build(array $entries): array
    {
        $closing = new OperationsMonthClosing(['user_id' => 1, 'month' => '2026-10', 'timezone' => 'Europe/Berlin']);
        $snapshot = ['schema' => 'railtime-payroll-preparation-v2', 'user_id' => 1, 'month' => '2026-10', 'timezone' => 'Europe/Berlin',
            'payroll_reference' => ['personnel_number' => '00017'], 'complete' => true, 'entries' => $entries];

        return LexwareMonthlyFile::build($closing, $snapshot, [
            ['source_code' => 'CODE1', 'target_code' => '0001', 'quantity_kind' => 'base'],
            ['source_code' => 'CODE3', 'target_code' => '0110', 'quantity_kind' => 'supplement'],
        ]);
    }

    public function test_clips_both_ends_of_the_month_and_counts_the_repeated_autumn_hour(): void
    {
        $file = $this->build([
            $this->entry(1, '2026-09-30T23:00:00+02:00', '2026-10-01T01:00:00+02:00', [$this->quantity('work', 1, 3600)]),
            $this->entry(2, '2026-10-25T01:30:00+02:00', '2026-10-25T03:30:00+01:00', [$this->quantity('work', 1, 10800)]),
            $this->entry(3, '2026-10-31T23:30:00+01:00', '2026-11-01T00:30:00+01:00', [$this->quantity('work', 1, 1800)]),
        ]);
        $this->assertSame(16200, $file['rows'][0]['raw_seconds']);
        $this->assertSame("Jahr;Monat;Personalnummer;Lohnartnummer;Wert\r\n2026;10;00017;0001;4,50\r\n", $file['content']);
    }

    public function test_unpaid_break_covers_elapsed_time_but_cannot_inflate_a_supplement(): void
    {
        $entry = $this->entry(1, '2026-10-02T08:00:00+02:00', '2026-10-02T16:00:00+02:00', [
            $this->quantity('work', 1, 25200), $this->quantity('break', 2, 3600, 0), $this->quantity('work', 3, 7200, 10000, true),
        ]);
        $file = $this->build([$entry]);
        $this->assertSame(1, $file['skipped_zero']);
        $this->assertSame(['7,00', '2,00'], array_column($file['rows'], 'value'));

        $entry['quantities'][2] = $this->quantity('work', 3, 27000, 10000, true);
        $this->expectException(ValidationException::class);
        $this->build([$entry]);
    }
}
