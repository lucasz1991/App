<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_user', function (Blueprint $table): void {
            $table->timestamp('last_opened_at')->nullable()->after('last_read_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('chat_user', function (Blueprint $table): void {
            $table->dropIndex(['last_opened_at']);
            $table->dropColumn('last_opened_at');
        });
    }
};
