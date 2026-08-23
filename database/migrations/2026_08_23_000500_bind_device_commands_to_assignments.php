<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('device_commands', 'device_assignment_id')) {
            Schema::table('device_commands', function (Blueprint $table): void {
                $table->foreignId('device_assignment_id')
                    ->nullable()
                    ->after('device_id')
                    ->constrained('device_assignments', 'id', 'dev_cmd_assignment_fk')
                    ->restrictOnDelete();
                $table->index(['device_assignment_id', 'status'], 'dev_cmd_assignment_status_idx');
            });
        }

        DB::table('device_commands')
            ->whereNull('device_assignment_id')
            ->orderBy('id')
            ->chunkById(200, function ($commands): void {
                foreach ($commands as $command) {
                    $assignmentId = DB::table('device_assignments')
                        ->where('device_id', $command->device_id)
                        ->whereNotNull('assigned_at')
                        ->where('assigned_at', '<=', $command->requested_at)
                        ->where(function ($query) use ($command): void {
                            $query->whereNull('returned_at')
                                ->orWhere('returned_at', '>=', $command->requested_at);
                        })
                        ->orderByDesc('assigned_at')
                        ->orderByDesc('id')
                        ->value('id');

                    if ($assignmentId) {
                        DB::table('device_commands')
                            ->where('id', $command->id)
                            ->whereNull('device_assignment_id')
                            ->update(['device_assignment_id' => $assignmentId]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Intentionally retained. Fresh installations receive this column
        // from the base migration, while upgraded installations receive it
        // here. A later rollback cannot reliably distinguish ownership and
        // therefore must never remove a base-owned safety binding.
    }
};
