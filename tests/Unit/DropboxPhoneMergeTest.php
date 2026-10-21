<?php

namespace Tests\Unit;

use App\Services\Dropbox\ThreeWayMerge;
use PHPUnit\Framework\TestCase;

class DropboxPhoneMergeTest extends TestCase
{
    public function test_app_only_phone_spacing_change_keeps_original_app_format_without_conflict(): void
    {
        $result = (new ThreeWayMerge)->merge(['phone' => ' 030 123 456 '], [
            ['baseline' => ['phone' => '030123456'], 'excel' => ['phone' => '030123456']],
        ]);

        $this->assertSame([], $result['conflicts']);
        $this->assertSame(' 030 123 456 ', $result['values']['phone']);
    }

    public function test_excel_only_mobile_spacing_change_is_not_a_business_change_and_real_number_change_is_imported_raw(): void
    {
        $merge = new ThreeWayMerge;
        $result = $merge->merge(['mobile' => '+49 170 1234567'], [
            ['baseline' => ['mobile' => '+49 170 1234567'], 'excel' => ['mobile' => "+49\t170\u{00A0}1234567"]],
        ]);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('+49 170 1234567', $result['values']['mobile']);

        $result = $merge->merge(['mobile' => '+49 170 1234567'], [
            ['baseline' => ['mobile' => '+49 170 1234567'], 'excel' => ['mobile' => '+49 170 7654321']],
        ]);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('+49 170 7654321', $result['values']['mobile']);
    }

    public function test_equivalent_number_variants_across_app_and_excel_copies_share_one_proposal_and_keep_app_format(): void
    {
        $merge = new ThreeWayMerge;
        $copies = [
            ['baseline' => ['phone' => '030111'], 'excel' => ['phone' => '030 222']],
            ['baseline' => ['phone' => '030111'], 'excel' => ['phone' => "030\n222"]],
        ];
        $result = $merge->merge(['phone' => '030 2 22'], $copies);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('030 2 22', $result['values']['phone']);

        $result = $merge->merge(['phone' => '030111'], $copies);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('030 222', $result['values']['phone']);
    }

    public function test_different_numbers_prefixes_and_other_fields_stay_strict_and_blank_deletions_remain_real_changes(): void
    {
        $merge = new ThreeWayMerge;
        $result = $merge->merge(['phone' => '030222', 'name' => 'App Name', 'contact_email' => 'name@example.invalid'], [
            ['baseline' => ['phone' => '030111', 'name' => 'Ursprung', 'contact_email' => 'alt@example.invalid'],
                'excel' => ['phone' => '030333', 'name' => 'App  Name', 'contact_email' => 'name @example.invalid']],
        ]);
        $this->assertArrayHasKey('phone', $result['conflicts']);
        $this->assertArrayHasKey('name', $result['conflicts']);
        $this->assertArrayHasKey('contact_email', $result['conflicts']);
        $this->assertSame('030222', $result['values']['phone']);

        $result = $merge->merge(['mobile' => '+49170123'], [
            ['baseline' => ['mobile' => '0170123'], 'excel' => ['mobile' => '0049170123']],
        ]);
        $this->assertArrayHasKey('mobile', $result['conflicts']);

        $result = $merge->merge(['phone' => '030111'], [
            ['baseline' => ['phone' => '030111'], 'excel' => ['phone' => '']],
        ]);
        $this->assertSame([], $result['conflicts']);
        $this->assertSame('', $result['values']['phone']);

        $result = $merge->merge(['phone' => '030222'], [
            ['baseline' => ['phone' => '030111'], 'excel' => ['phone' => '']],
        ]);
        $this->assertArrayHasKey('phone', $result['conflicts']);
        $this->assertSame('030222', $result['values']['phone']);
    }
}
