<?php

use App\Models\ChatMessage;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('files')
            || ! Schema::hasTable('chat_messages')
            || ! Schema::hasColumn('chat_messages', 'message_type')) {
            return;
        }

        $legacyFiles = DB::table('files')
            ->where('fileable_type', ChatMessage::class)
            ->where(function ($query): void {
                $query->whereRaw('LOWER(name) LIKE ?', ['sprachnachricht-%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['sprachnachricht.%'])
                    ->orWhereRaw('LOWER(name) LIKE ?', ['sprachnachricht_%']);
            });

        $messageIds = (clone $legacyFiles)
            ->whereNotNull('fileable_id')
            ->pluck('fileable_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        if ($messageIds->isEmpty()) {
            return;
        }

        DB::table('chat_messages')
            ->whereIn('id', $messageIds)
            ->update(['message_type' => 'voice']);

        $legacyFiles->update([
            'type' => 'voice',
            'mime_type' => DB::raw("CASE
                WHEN LOWER(name) LIKE '%.ogg' THEN 'audio/ogg'
                WHEN LOWER(name) LIKE '%.m4a' OR LOWER(name) LIKE '%.mp4' THEN 'audio/mp4'
                WHEN LOWER(name) LIKE '%.mp3' THEN 'audio/mpeg'
                WHEN LOWER(name) LIKE '%.wav' THEN 'audio/wav'
                WHEN LOWER(name) LIKE '%.aac' THEN 'audio/aac'
                ELSE 'audio/webm'
            END"),
        ]);
    }

    public function down(): void
    {
        // Reine Datenkorrektur: Der fruehere, browserabhaengige MIME-Wert ist
        // nicht verlaesslich rekonstruierbar und wird daher nicht zurueckgesetzt.
    }
};
