<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_identity_syncs', function (Blueprint $table): void {
            $table->timestamp('last_enqueued_at')->nullable()->after('requested_at');
            $table->index(
                ['status', 'last_enqueued_at'],
                'dev_id_sync_queue_lease_idx',
            );
        });
    }

    public function down(): void
    {
        // Interrupted-install recovery may already have removed the owning
        // table or its index before Laravel reaches this later migration.
        if (! Schema::hasTable('device_identity_syncs')
            || ! Schema::hasColumn('device_identity_syncs', 'last_enqueued_at')) {
            return;
        }

        $hasLeaseIndex = Schema::hasIndex('device_identity_syncs', 'dev_id_sync_queue_lease_idx');
        Schema::table('device_identity_syncs', function (Blueprint $table) use ($hasLeaseIndex): void {
            if ($hasLeaseIndex) {
                $table->dropIndex('dev_id_sync_queue_lease_idx');
            }
            $table->dropColumn('last_enqueued_at');
        });
    }
};
