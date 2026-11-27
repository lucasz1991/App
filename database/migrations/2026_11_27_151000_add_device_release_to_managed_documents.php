<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Verbindliche Dateien automatisch auf Firmen-PCs ablegen (Ordner RailTime\Listen, vertrauenswürdig
 * für Excel). Verteilt wird nur genau die Version, die ein Superadmin dafür freigegeben hat; eine
 * neu hochgeladene Version erreicht die Geräte erst nach erneuter Freigabe.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('managed_documents') || Schema::hasColumn('managed_documents', 'device_release_version_id')) {
            return;
        }

        Schema::table('managed_documents', function (Blueprint $table): void {
            $table->foreignId('device_release_version_id')->nullable()->after('notify_on_update')
                ->constrained('managed_document_versions', 'id', 'managed_documents_device_release_fk')->nullOnDelete();
            $table->foreignId('device_released_by')->nullable()->after('device_release_version_id')
                ->constrained('users', 'id', 'managed_documents_device_released_by_fk')->nullOnDelete();
            $table->timestamp('device_released_at')->nullable()->after('device_released_by');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('managed_documents', 'device_release_version_id')) {
            return;
        }
        Schema::table('managed_documents', function (Blueprint $table): void {
            $table->dropForeign('managed_documents_device_release_fk');
            $table->dropForeign('managed_documents_device_released_by_fk');
            $table->dropColumn(['device_release_version_id', 'device_released_by', 'device_released_at']);
        });
    }
};
