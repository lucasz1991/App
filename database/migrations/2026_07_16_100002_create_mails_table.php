<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('mails', function (Blueprint $table) {
            $table->id();
            $table->string('type')->default('message'); // message | mail | both
            $table->boolean('status')->default(false);  // true = alle Empfaenger versorgt
            $table->json('content');                    // subject, header, body, link
            $table->json('recipients');                 // [{user_id|email, status}]
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('mails');
    }
};
