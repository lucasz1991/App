<?php

use App\Models\EmployeeDocumentRequirement;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('employee_document_requirements') || ! Schema::hasTable('files')) {
            return;
        }

        DB::table('employee_document_requirements')
            ->whereNotNull('file_id')
            ->orderBy('id')
            ->chunkById(100, function ($requirements): void {
                foreach ($requirements as $requirement) {
                    DB::table('files')
                        ->where('id', $requirement->file_id)
                        ->update([
                            'fileable_type' => EmployeeDocumentRequirement::class,
                            'fileable_id' => $requirement->id,
                            'filepool_id' => null,
                            'folder_id' => null,
                            'type' => 'employee-document',
                            'updated_at' => now(),
                        ]);

                    DB::table('employee_document_requirements')
                        ->where('id', $requirement->id)
                        ->update([
                            'file_id' => null,
                            'verified_by' => null,
                            'verified_at' => null,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        // The former file-pool assignment is intentionally not reconstructed:
        // its source pool cannot be derived reliably after separation.
    }
};
