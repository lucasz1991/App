<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_creatives', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('type', 24)->index();
            $table->string('status', 24)->default('draft')->index();
            $table->string('title', 160);
            $table->json('shared_content');
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('marketing_creative_variants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('marketing_creative_id')->constrained()->cascadeOnDelete();
            $table->string('format', 24);
            $table->json('builder_data');
            $table->longText('html');
            $table->longText('css');
            $table->char('content_hash', 64);
            $table->unsignedInteger('version')->default(1);
            $table->timestamps();

            $table->unique(['marketing_creative_id', 'format'], 'marketing_variant_creative_format_unique');
        });

        Schema::create('marketing_assets', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('original_name');
            $table->string('disk', 40)->default('private');
            $table->string('path')->unique();
            $table->string('mime_type', 80);
            $table->string('extension', 12);
            $table->unsignedBigInteger('size');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->char('sha256', 64)->index();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('marketing_renders', function (Blueprint $table): void {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('marketing_creative_id')->constrained()->cascadeOnDelete();
            $table->foreignId('marketing_creative_variant_id')->constrained()->cascadeOnDelete();
            $table->string('format', 24)->index();
            $table->char('fingerprint', 64)->unique();
            $table->string('status', 24)->default('pending')->index();
            $table->string('disk', 40)->default('private');
            $table->string('path')->nullable();
            $table->string('mime_type', 80)->nullable();
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->text('error')->nullable();
            $table->timestamp('rendered_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_renders');
        Schema::dropIfExists('marketing_assets');
        Schema::dropIfExists('marketing_creative_variants');
        Schema::dropIfExists('marketing_creatives');
    }
};
