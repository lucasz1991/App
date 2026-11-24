<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duty_activities', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('title', 160)->nullable();
            $table->string('description', 500)->nullable();
            $table->boolean('active')->default(true);
            $table->boolean('requirements_confirmed')->default(false);
            $table->foreignId('qualification_bundle_id')->nullable()->constrained('qualification_bundles')->restrictOnDelete();
            $table->string('proof_type', 80)->default('Einsatzbericht');
            $table->boolean('wagon_list')->default(false);
            $table->boolean('customer_acceptance')->default(false);
            $table->boolean('individual_proof')->default(false);
            $table->unsignedInteger('revision')->default(1);
        });
        Schema::create('duty_activity_codes', function (Blueprint $table) {
            $table->string('lookup', 60)->primary();
            $table->foreignId('duty_activity_id')->constrained()->cascadeOnDelete();
        });
        foreach (['Azf' => 'Arbeitszugführer', 'Azf/Rb' => 'Arbeitszugführer / Rangierbegleiter', 'QP' => 'Qualitätsprüfer', 'RB' => 'Rangierbegleiter', 'RB/WGM' => 'Rangierbegleiter / Wagenmeister', 'WGM' => 'Wagenmeister', 'WGM/RB' => 'Wagenmeister / Rangierbegleiter', 'WSU' => null, 'ZBV' => null, 'WU' => 'Wagenuntersuchung', 'WTU' => 'Wagentechnische Untersuchung', 'TF' => 'Triebfahrzeugführer'] as $code => $title) {
            $wagon = in_array($code, ['WGM', 'WU', 'WTU'], true);
            $id = DB::table('duty_activities')->insertGetId(['code' => $code, 'title' => $title, 'wagon_list' => $wagon, 'proof_type' => $wagon ? 'Wagenliste' : 'Einsatzbericht']);
            DB::table('duty_activity_codes')->insert(['lookup' => mb_strtolower($code), 'duty_activity_id' => $id]);
        }
        Schema::create('duty_activity_user', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('duty_activity_id')->constrained()->restrictOnDelete();
            $table->foreignId('confirmed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at');
            $table->primary(['user_id', 'duty_activity_id']);
        });
        Schema::table('shift_sections', fn (Blueprint $table) => $table->json('activity_requirements')->nullable());
        Schema::table('shifts', fn (Blueprint $table) => $table->json('activity_requirements')->nullable());
    }

    public function down(): void
    {
        Schema::table('shift_sections', fn (Blueprint $table) => $table->dropColumn('activity_requirements'));
        Schema::table('shifts', fn (Blueprint $table) => $table->dropColumn('activity_requirements'));
        Schema::dropIfExists('duty_activity_user');
        Schema::dropIfExists('duty_activity_codes');
        Schema::dropIfExists('duty_activities');
    }
};
