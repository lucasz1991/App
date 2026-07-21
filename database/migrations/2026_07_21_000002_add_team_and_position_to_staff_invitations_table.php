<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff_invitations', function (Blueprint $table) {
            $table->foreignId('team_id')
                ->nullable()
                ->after('role')
                ->constrained('teams')
                ->nullOnDelete();
            $table->string('position')->nullable()->after('team_id');
        });
    }

    public function down(): void
    {
        Schema::table('staff_invitations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('team_id');
            $table->dropColumn('position');
        });
    }
};
