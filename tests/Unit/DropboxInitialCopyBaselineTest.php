<?php

namespace Tests\Unit;

use App\Services\Dropbox\InitialCopyBaseline;
use App\Services\Dropbox\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

class DropboxInitialCopyBaselineTest extends TestCase
{
    private function merge(array $app, array $excel, array $previous = []): array
    {
        $baseline = (new InitialCopyBaseline)->forCopy($app, $excel, array_map(fn ($values) => (object) ['baseline' => $values], $previous), 'contacts');

        return (new ThreeWayMerge)->merge($app, [['baseline' => $baseline, 'excel' => $excel]]);
    }

    public function test_new_contact_layout_enriches_missing_fields_without_erasing_existing_data(): void
    {
        $result = $this->merge(['first_name' => 'Erika', 'city' => 'Berlin', 'phone' => ''], ['name' => 'Erika Muster', 'city' => '', 'phone' => '030123']);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame(['first_name' => 'Erika', 'city' => 'Berlin', 'phone' => '030123', 'name' => 'Erika Muster'], $result['values']);
    }

    public function test_two_populated_values_without_a_common_baseline_require_a_field_decision(): void
    {
        $result = $this->merge(['city' => 'Berlin'], ['city' => 'Hamburg']);
        $this->assertArrayHasKey('city', $result['conflicts']);
        $this->assertSame('Berlin', $result['values']['city']);
    }

    public function test_stale_copy_does_not_undo_a_recorded_app_change_or_deletion(): void
    {
        foreach (['Hamburg', ''] as $current) {
            $result = $this->merge(['city' => $current], ['city' => 'Berlin'], [['city' => 'Berlin']]);
            $this->assertSame([], $result['conflicts']);
            $this->assertSame($current, $result['values']['city']);
        }
    }

    public function test_app_deletion_and_a_different_new_copy_value_remain_a_conflict(): void
    {
        foreach (['', null] as $current) {
            $result = $this->merge(['phone' => $current], ['phone' => 'NEU'], [['phone' => 'ALT']]);
            $this->assertArrayHasKey('phone', $result['conflicts']);
            $this->assertSame($current, $result['values']['phone']);
        }
    }
}
