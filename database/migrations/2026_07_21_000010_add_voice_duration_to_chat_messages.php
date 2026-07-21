<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'voice_duration_seconds')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->unsignedSmallInteger('voice_duration_seconds')->nullable()->after('view_once');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('chat_messages', 'voice_duration_seconds')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropColumn('voice_duration_seconds');
            });
        }
    }
};
