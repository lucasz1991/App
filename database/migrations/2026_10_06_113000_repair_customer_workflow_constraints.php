<?php

use App\Support\Operations\CustomerWorkflowSchemaRepair;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        // Also reconcile installations where a retry already recorded the original migration.
        CustomerWorkflowSchemaRepair::repair();
    }

    public function down(): void
    {
        // The constraints are part of the original schema, not optional data to roll back.
    }
};
