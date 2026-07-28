<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('chat_user')) {
            return;
        }

        if (! Schema::hasColumn('chat_user', 'joined_at')) {
            Schema::table('chat_user', function (Blueprint $table): void {
                $table->timestamp('joined_at')->nullable();
            });
        }

        if (! Schema::hasColumn('chat_user', 'hidden_at')) {
            Schema::table('chat_user', function (Blueprint $table): void {
                $table->timestamp('hidden_at')->nullable();
            });
        }

        if (! Schema::hasColumn('chat_user', 'cleared_at')) {
            Schema::table('chat_user', function (Blueprint $table): void {
                $table->timestamp('cleared_at')->nullable();
            });
        }

        if (Schema::hasColumn('chat_user', 'created_at')) {
            DB::table('chat_user')
                ->whereNull('joined_at')
                ->whereNotNull('created_at')
                ->update(['joined_at' => DB::raw('created_at')]);
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('chat_user')) {
            return;
        }

        foreach (['cleared_at', 'hidden_at', 'joined_at'] as $column) {
            if (Schema::hasColumn('chat_user', $column)) {
                Schema::table('chat_user', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }
    }
};
