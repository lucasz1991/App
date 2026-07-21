<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('chat_messages', 'message_type')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->string('message_type', 24)->default('text')->after('body');
            });
        }

        if (! Schema::hasColumn('chat_messages', 'view_once')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->boolean('view_once')->default(false)->after('message_type');
            });
        }

        if (! Schema::hasTable('chat_message_views')) {
            Schema::create('chat_message_views', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('chat_message_id');
                $table->unsignedBigInteger('user_id');
                $table->timestamp('viewed_at');
                $table->timestamps();

                $table->unique(['chat_message_id', 'user_id'], 'cmv_message_user_uq');
                $table->foreign('chat_message_id', 'cmv_message_fk')
                    ->references('id')
                    ->on('chat_messages')
                    ->cascadeOnDelete();
                $table->foreign('user_id', 'cmv_user_fk')
                    ->references('id')
                    ->on('users')
                    ->cascadeOnDelete();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_views');

        if (Schema::hasColumn('chat_messages', 'view_once')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropColumn('view_once');
            });
        }

        if (Schema::hasColumn('chat_messages', 'message_type')) {
            Schema::table('chat_messages', function (Blueprint $table): void {
                $table->dropColumn('message_type');
            });
        }
    }
};
