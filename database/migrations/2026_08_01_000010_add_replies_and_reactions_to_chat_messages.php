<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->foreignId('reply_to_message_id')
                ->nullable()
                ->after('user_id')
                ->constrained('chat_messages')
                ->nullOnDelete();
            $table->softDeletes();
        });

        Schema::create('chat_message_reactions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('chat_message_id')->constrained('chat_messages')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('emoji', 16);
            $table->timestamps();

            $table->unique(['chat_message_id', 'user_id']);
            $table->index(['chat_message_id', 'emoji']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('chat_message_reactions');

        Schema::table('chat_messages', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('reply_to_message_id');
            $table->dropSoftDeletes();
        });
    }
};
