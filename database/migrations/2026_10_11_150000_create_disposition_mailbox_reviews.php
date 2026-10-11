<?php

use App\Models\AiIntakeMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('disposition_mailbox_reviews')) {
            Schema::create('disposition_mailbox_reviews', function (Blueprint $table): void {
                $table->id();
                $table->char('mailbox_id', 64);
                $table->char('folder_hash', 64);
                $table->unsignedBigInteger('uid_validity');
                $table->unsignedBigInteger('uid');
                $table->string('classification', 20)->default('unreviewed');
                $table->unsignedBigInteger('intake_id')->nullable();
                $table->unsignedBigInteger('reviewed_by')->nullable();
                $table->unsignedInteger('revision')->default(1);
                $table->timestamps();
                $table->unique(['mailbox_id', 'folder_hash', 'uid_validity', 'uid'], 'dmr_mail_identity_unique');
            });
        }
        foreach (['ai_intake_messages', 'ai_intake_deliveries'] as $name) {
            if (Schema::hasTable($name) && ! Schema::hasColumn($name, 'mailbox_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->char('mailbox_id', 64)->nullable()->index());
            }
        }
        // Index existing encrypted source metadata without changing immutable originals.
        if (Schema::hasColumn('ai_intake_messages', 'mailbox_id')) {
            AiIntakeMessage::whereNull('mailbox_id')->where('direction', 'inbound')->chunkById(200, function ($messages): void {
                foreach ($messages as $message) {
                    try {
                        $id = $message->metadata['mailbox_id'] ?? null;
                        if (is_string($id) && preg_match('/^[a-f0-9]{64}$/D', $id)) {
                            DB::table('ai_intake_messages')->where('id', $message->id)->whereNull('mailbox_id')->update(['mailbox_id' => $id]);
                        }
                    } catch (Throwable) {
                        // A missing historical encryption key must not prevent the additive migration.
                    }
                }
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('disposition_mailbox_reviews');
        foreach (['ai_intake_messages', 'ai_intake_deliveries'] as $name) {
            if (Schema::hasColumn($name, 'mailbox_id')) {
                Schema::table($name, fn (Blueprint $table) => $table->dropColumn('mailbox_id'));
            }
        }
    }
};
