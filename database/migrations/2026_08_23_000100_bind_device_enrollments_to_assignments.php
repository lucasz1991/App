<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() === 'sqlite') {
            // SQLite cannot later remove one named in-table FK while
            // rebuilding a column. The service invariant enforces the same
            // assignment relationship in tests; MySQL production gets the FK.
            Schema::table('device_enrollments', function (Blueprint $table): void {
                $table->unsignedBigInteger('device_assignment_id')->nullable()->after('user_id');
                $table->index(
                    ['device_assignment_id', 'status'],
                    'dev_enroll_assignment_status_idx',
                );
            });
        } else {
            Schema::table('device_enrollments', function (Blueprint $table): void {
                $table->foreignId('device_assignment_id')
                    ->nullable()
                    ->after('user_id')
                    ->constrained('device_assignments', 'id', 'dev_enroll_assignment_fk')
                    ->nullOnDelete();
                $table->index(
                    ['device_assignment_id', 'status'],
                    'dev_enroll_assignment_status_idx',
                );
            });
        }

        // Invitations created before assignment binding existed cannot be
        // proven to belong to the current handover. Fail closed instead of
        // silently attaching them to whichever employee is assigned now.
        DB::table('device_enrollments')
            ->whereNull('device_assignment_id')
            ->whereIn('status', ['invited', 'claimed'])
            ->update([
                'status' => 'revoked',
                'revoked_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        // The original device migration has an interrupted-install recovery
        // test that may rebuild its tables independently. A later rollback
        // must therefore also tolerate an already-absent additive column.
        if (! Schema::hasColumn('device_enrollments', 'device_assignment_id')) {
            return;
        }

        if (DB::getDriverName() === 'sqlite') {
            if (Schema::hasIndex('device_enrollments', 'dev_enroll_assignment_status_idx')) {
                Schema::table('device_enrollments', function (Blueprint $table): void {
                    $table->dropIndex('dev_enroll_assignment_status_idx');
                });
            }
            Schema::table('device_enrollments', function (Blueprint $table): void {
                $table->dropColumn('device_assignment_id');
            });

            return;
        }

        Schema::table('device_enrollments', function (Blueprint $table): void {
            $table->dropForeign('dev_enroll_assignment_fk');
            $table->dropIndex('dev_enroll_assignment_status_idx');
            $table->dropColumn('device_assignment_id');
        });
    }
};
