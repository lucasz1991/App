<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULT_INTRO = 'Der RailTime-Wissenspool enth\u00e4lt redaktionell gepr\u00fcfte Hinweise zu Bedienung, Abl\u00e4ufen und internen Begriffen. Nutze Detailwissen nur, wenn es zur Frage passt.';

    public function up(): void
    {
        Schema::create('assistant_knowledge_topics', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['is_active', 'sort_order'], 'assistant_knowledge_topics_active_order');
        });

        Schema::create('assistant_knowledge_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('topic_id')
                ->constrained('assistant_knowledge_topics')
                ->cascadeOnUpdate()
                ->restrictOnDelete();
            $table->string('title', 180);
            $table->text('summary')->nullable();
            $table->longText('content');
            $table->json('keywords')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('include_in_baseline')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['topic_id', 'is_active', 'sort_order'], 'assistant_knowledge_entries_topic_active');
            $table->index(['include_in_baseline', 'is_active'], 'assistant_knowledge_entries_baseline');
        });

        $exists = DB::table('settings')
            ->where('type', 'assistant')
            ->where('key', 'knowledge_intro')
            ->exists();

        if (! $exists) {
            DB::table('settings')->insert([
                'type' => 'assistant',
                'key' => 'knowledge_intro',
                'value' => json_encode(self::DEFAULT_INTRO, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_knowledge_entries');
        Schema::dropIfExists('assistant_knowledge_topics');

        $setting = DB::table('settings')
            ->where('type', 'assistant')
            ->where('key', 'knowledge_intro')
            ->first();

        if ($setting && json_decode((string) $setting->value, true) === self::DEFAULT_INTRO) {
            DB::table('settings')->where('id', $setting->id)->delete();
        }
    }
};
