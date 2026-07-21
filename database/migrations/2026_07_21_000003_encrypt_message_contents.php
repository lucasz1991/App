<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('messages')) {
            Schema::table('messages', function (Blueprint $table): void {
                $table->text('subject')->change();
            });

            DB::table('messages')
                ->select(['id', 'subject', 'message'])
                ->orderBy('id')
                ->chunkById(100, function ($messages): void {
                    foreach ($messages as $message) {
                        DB::table('messages')
                            ->where('id', $message->id)
                            ->update([
                                'subject' => Crypt::encryptString((string) $message->subject),
                                'message' => Crypt::encryptString((string) $message->message),
                            ]);
                    }
                });
        }

        if (Schema::hasTable('chat_messages')) {
            DB::table('chat_messages')
                ->select(['id', 'body'])
                ->orderBy('id')
                ->chunkById(100, function ($messages): void {
                    foreach ($messages as $message) {
                        DB::table('chat_messages')
                            ->where('id', $message->id)
                            ->update([
                                'body' => Crypt::encryptString((string) $message->body),
                            ]);
                    }
                });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('messages')) {
            DB::table('messages')
                ->select(['id', 'subject', 'message'])
                ->orderBy('id')
                ->chunkById(100, function ($messages): void {
                    foreach ($messages as $message) {
                        DB::table('messages')
                            ->where('id', $message->id)
                            ->update([
                                'subject' => Crypt::decryptString((string) $message->subject),
                                'message' => Crypt::decryptString((string) $message->message),
                            ]);
                    }
                });

            Schema::table('messages', function (Blueprint $table): void {
                $table->string('subject')->change();
            });
        }

        if (Schema::hasTable('chat_messages')) {
            DB::table('chat_messages')
                ->select(['id', 'body'])
                ->orderBy('id')
                ->chunkById(100, function ($messages): void {
                    foreach ($messages as $message) {
                        DB::table('chat_messages')
                            ->where('id', $message->id)
                            ->update([
                                'body' => Crypt::decryptString((string) $message->body),
                            ]);
                    }
                });
        }
    }
};
