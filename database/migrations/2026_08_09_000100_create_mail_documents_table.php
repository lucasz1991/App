<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_documents', function (Blueprint $table): void {
            $table->id();
            // Routen-Schluessel wie bei MarketingCreative: numerische IDs
            // tauchen nie in URLs auf.
            $table->uuid('public_id')->unique();

            // unique statt index: es gibt genau ein Dokument je Art. Hell und
            // Dunkel sind keine eigenen Zeilen, sie entstehen aus der Palette.
            $table->string('kind', 24)->unique();
            $table->string('status', 24)->default('draft')->index();

            // Arbeitsstand des Editors. Das CSS steht in einer eigenen Spalte,
            // weil es getrennt von HTML gehaertet wird.
            $table->json('builder_data');
            $table->longText('html');
            $table->longText('css');

            // Abzug der veroeffentlichten Fassung. Der Mailversand liest
            // ausschliesslich hier, waehrend am Entwurf weitergearbeitet wird;
            // ist nichts veroeffentlicht, greift die Blade-/Master-Quelle.
            $table->longText('published_html')->nullable();
            $table->longText('published_css')->nullable();
            $table->timestamp('published_at')->nullable();

            // Optimistische Sperre wie im Marketing-Studio: sha256 ueber
            // rekursiv sortierte builder_data + html + css, gebildet NACH der
            // Haertung. version 1 kennzeichnet ein unberuehrtes Startlayout.
            $table->char('content_hash', 64);
            $table->unsignedInteger('version')->default(1);

            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_documents');
    }
};
