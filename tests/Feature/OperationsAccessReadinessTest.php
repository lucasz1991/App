<?php

namespace Tests\Feature;

use App\Support\Operations\OperationsAccess;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OperationsAccessReadinessTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->assertSame('sqlite', DB::connection()->getDriverName());
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_complete_current_schema_is_ready(): void
    {
        $this->createRequiredSchema();

        $this->assertSame('main', Schema::getCurrentSchemaName());
        $this->assertTrue(OperationsAccess::ready());
    }

    public function test_foreign_schema_cannot_supply_a_missing_local_table(): void
    {
        $this->createRequiredSchema();
        Schema::drop('operation_inquiries');
        DB::statement("ATTACH DATABASE ':memory:' AS foreign_qa");
        Schema::create('foreign_qa.operation_inquiries', fn (Blueprint $table) => $table->id());

        // Reproduce the old, unqualified listing's false-positive precondition.
        $this->assertContains('operation_inquiries', Schema::getTableListing(null, false));
        $this->assertNotContains('operation_inquiries', Schema::getTableListing('main', false));
        $this->assertFalse(OperationsAccess::ready());

        Schema::create('operation_inquiries', fn (Blueprint $table) => $table->id());
        $this->assertTrue(OperationsAccess::ready());
    }

    public function test_readiness_preserves_the_connection_table_prefix(): void
    {
        DB::connection()->setTablePrefix('qa_');
        $this->createRequiredSchema();

        $this->assertContains('qa_operation_inquiries', Schema::getTableListing('main', false));
        $this->assertTrue(OperationsAccess::ready());
    }

    public function test_required_columns_are_still_checked_without_stale_readiness(): void
    {
        $this->createRequiredSchema();
        $this->assertTrue(OperationsAccess::ready());

        Schema::table('shift_assignments', fn (Blueprint $table) => $table->dropColumn('plan_revision'));
        $this->assertFalse(OperationsAccess::ready());

        Schema::table('shift_assignments', fn (Blueprint $table) => $table->unsignedInteger('plan_revision')->nullable());
        $this->assertTrue(OperationsAccess::ready());
    }

    private function createRequiredSchema(): void
    {
        foreach (['operation_inquiries', 'operation_audits', 'qualification_types', 'employee_qualifications', 'shift_qualification_requirements', 'absence_requests', 'operations_rule_profiles', 'work_time_entries', 'work_time_events', 'work_time_revisions', 'work_time_exports', 'work_time_export_items'] as $name) {
            Schema::create($name, fn (Blueprint $table) => $table->id());
        }

        Schema::create('shifts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('revision');
            $table->unsignedInteger('published_revision')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->json('published_snapshot')->nullable();
            $table->unsignedInteger('planned_break_minutes');
        });
        Schema::create('shift_assignments', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('plan_revision')->nullable();
        });
    }
}
