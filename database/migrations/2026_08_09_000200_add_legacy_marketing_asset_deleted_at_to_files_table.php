<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ergaenzt die Spalte legacy_marketing_asset_deleted_at in `files`.
 *
 * WARUM ES SIE BRAUCHT
 * MarketingFileSourceService fragt sie an zwei Stellen ab — in
 * candidateFiles() als whereNull() und in fileIsInScope() als
 * Eigenschaftszugriff. Angelegt wurde sie aber nie: die Migration
 * 2026_08_08_000100 ergaenzt ihre drei Geschwister
 * (legacy_marketing_asset_public_id, content_sha256, image_width/height),
 * diese eine fehlt. Ergebnis in der Produktion:
 *
 *   SQLSTATE[42S22]: Unknown column 'legacy_marketing_asset_deleted_at'
 *   in 'WHERE' — /administrator/marketing/motive antwortet mit 500.
 *
 * Bewusst eine EIGENE Migration statt einer Ergaenzung in 2026_08_08_000100:
 * jene ist auf manchen Umgebungen bereits gelaufen und wuerde dort nicht noch
 * einmal ausgefuehrt. So bekommen bestehende wie frische Installationen die
 * Spalte.
 *
 * Fachlich bleibt das Verhalten unveraendert: es gibt bislang keine Stelle,
 * die einen Wert SETZT. Die Spalte ist damit ueberall NULL, die Pruefung
 * greift also nicht — genau so, wie die Anwendung sich vor dem Fehler
 * verhalten haette, waere die Spalte von Anfang an dagewesen.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('files')) {
            return;
        }

        if (Schema::hasColumn('files', 'legacy_marketing_asset_deleted_at')) {
            return;
        }

        Schema::table('files', function (Blueprint $table): void {
            $table->timestamp('legacy_marketing_asset_deleted_at')->nullable();
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('files')) {
            return;
        }

        if (! Schema::hasColumn('files', 'legacy_marketing_asset_deleted_at')) {
            return;
        }

        Schema::table('files', function (Blueprint $table): void {
            $table->dropColumn('legacy_marketing_asset_deleted_at');
        });
    }
};
