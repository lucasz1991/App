<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $this->encryptPlaintextColumn('messages', 'subject');
        $this->encryptPlaintextColumn('messages', 'message');
        $this->encryptPlaintextColumn('chat_messages', 'body');
    }

    public function down(): void
    {
        // Die vorherige Migration verwaltet die eigentliche Rueckmigration.
    }

    private function encryptPlaintextColumn(string $table, string $column): void
    {
        DB::table($table)
            ->select(['id', $column])
            ->orderBy('id')
            ->chunkById(100, function ($rows) use ($table, $column): void {
                foreach ($rows as $row) {
                    $value = (string) $row->{$column};

                    try {
                        Crypt::decryptString($value);

                        continue;
                    } catch (\Throwable) {
                        // Kein gueltiger Laravel-Ciphertext: als Altbestand verschluesseln.
                    }

                    DB::table($table)
                        ->where('id', $row->id)
                        ->update([$column => Crypt::encryptString($value)]);
                }
            });
    }
};
