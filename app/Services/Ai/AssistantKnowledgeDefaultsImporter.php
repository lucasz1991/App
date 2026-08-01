<?php

namespace App\Services\Ai;

use App\Support\Ai\AssistantKnowledgeDefaults;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class AssistantKnowledgeDefaultsImporter
{
    public function __construct(
        private readonly AssistantKnowledgePool $knowledge,
    ) {}

    /** @return array<string, int|bool|string> */
    public function import(bool $dryRun = false): array
    {
        $this->assertSchemaReady();
        $topics = AssistantKnowledgeDefaults::topics();
        $this->validateManifest($topics);

        $result = [
            'revision' => AssistantKnowledgeDefaults::REVISION,
            'dry_run' => $dryRun,
            'topics_created' => 0,
            'topics_updated' => 0,
            'topics_unchanged' => 0,
            'topics_skipped' => 0,
            'entries_created' => 0,
            'entries_updated' => 0,
            'entries_unchanged' => 0,
            'entries_skipped' => 0,
            'baseline_deferred' => 0,
            'conflicts' => 0,
            'customized_skipped' => 0,
            'deleted_skipped' => 0,
        ];

        try {
            DB::transaction(function () use ($topics, $dryRun, &$result): void {
                $baselineQuery = DB::table('assistant_knowledge_entries')
                    ->whereNull('deleted_at')
                    ->where('include_in_baseline', true);

                if (! $dryRun) {
                    $baselineQuery->lockForUpdate();
                }

                // The editor limits every non-deleted baseline assignment,
                // including temporarily inactive entries. The importer must
                // use the same definition or it could create a ninth flag.
                $baselineUsed = (int) $baselineQuery->count();

                foreach ($topics as $topicDefinition) {
                    $topicQuery = DB::table('assistant_knowledge_topics')
                        ->where('source_key', $topicDefinition['source_key']);

                    if (! $dryRun) {
                        $topicQuery->lockForUpdate();
                    }

                    $topic = $topicQuery->first();

                    if (! $topic) {
                        $collision = DB::table('assistant_knowledge_topics')
                            ->where('name', $topicDefinition['name'])
                            ->whereNull('source_key')
                            ->exists();

                        if ($collision) {
                            $result['topics_skipped']++;
                            $result['conflicts']++;
                            $result['entries_skipped'] += count($topicDefinition['entries']);

                            continue;
                        }

                        $result['topics_created']++;
                        $topicId = null;

                        if (! $dryRun) {
                            $payload = $this->topicPayload($topicDefinition);
                            $payload['source_hash'] = $this->topicHash($payload);
                            $payload['imported_at'] = now();
                            $payload['created_at'] = now();
                            $payload['updated_at'] = now();
                            $topicId = (int) DB::table('assistant_knowledge_topics')->insertGetId($payload);
                        }
                    } else {
                        $topicId = (int) $topic->id;
                        $currentHash = $this->topicHash((array) $topic);
                        $pristine = is_string($topic->source_hash)
                            && $topic->source_hash !== ''
                            && hash_equals($topic->source_hash, $currentHash);

                        if ($topic->deleted_at !== null) {
                            $result['topics_skipped']++;
                            $result['entries_skipped'] += count($topicDefinition['entries']);
                            $result['deleted_skipped']++;

                            continue;
                        }

                        if (! $pristine) {
                            $result['topics_skipped']++;
                            $result['customized_skipped']++;
                        } else {
                            $payload = $this->topicPayload($topicDefinition);
                            $payload['deleted_at'] = null;
                            $nextHash = $this->topicHash($payload);

                            if ($currentHash === $nextHash && $topic->deleted_at === null) {
                                $result['topics_unchanged']++;
                            } else {
                                $result['topics_updated']++;
                                if (! $dryRun) {
                                    DB::table('assistant_knowledge_topics')->where('id', $topicId)->update([
                                        ...$payload,
                                        'source_hash' => $nextHash,
                                        'imported_at' => now(),
                                        'updated_at' => now(),
                                    ]);
                                }
                            }
                        }
                    }

                    foreach ($topicDefinition['entries'] as $entryDefinition) {
                        $entryQuery = DB::table('assistant_knowledge_entries')
                            ->where('source_key', $entryDefinition['source_key']);

                        if (! $dryRun) {
                            $entryQuery->lockForUpdate();
                        }

                        $entry = $entryQuery->first();

                        if (! $entry && $topicId !== null) {
                            $collision = DB::table('assistant_knowledge_entries')
                                ->where('topic_id', $topicId)
                                ->where('title', $entryDefinition['title'])
                                ->whereNull('source_key')
                                ->exists();

                            if ($collision) {
                                $result['entries_skipped']++;
                                $result['conflicts']++;

                                continue;
                            }
                        }

                        if ($entry !== null) {
                            $currentTopicKey = (string) (DB::table('assistant_knowledge_topics')
                                ->where('id', $entry->topic_id)
                                ->value('source_key') ?? '');
                            $currentHash = $this->entryHash((array) $entry, $currentTopicKey);
                            $pristine = is_string($entry->source_hash)
                                && $entry->source_hash !== ''
                                && hash_equals($entry->source_hash, $currentHash);

                            if ($entry->deleted_at !== null) {
                                $result['entries_skipped']++;
                                $result['deleted_skipped']++;

                                continue;
                            }

                            if (! $pristine) {
                                $result['entries_skipped']++;
                                $result['customized_skipped']++;

                                continue;
                            }
                        }

                        $wasBaseline = $entry !== null && (bool) $entry->include_in_baseline;
                        $effectiveBaseline = (bool) $entryDefinition['include_in_baseline'];

                        if ($effectiveBaseline && ($baselineUsed - ($wasBaseline ? 1 : 0)) >= AssistantKnowledgePool::BASELINE_ENTRY_LIMIT) {
                            $effectiveBaseline = false;
                            $result['baseline_deferred']++;
                        }

                        if (! $entry) {
                            $result['entries_created']++;

                            if (! $dryRun) {
                                $payload = $this->entryPayload($entryDefinition, (int) $topicId, $effectiveBaseline);
                                $payload['source_hash'] = $this->entryHash($payload, $topicDefinition['source_key']);
                                $payload['imported_at'] = now();
                                $payload['created_at'] = now();
                                $payload['updated_at'] = now();
                                DB::table('assistant_knowledge_entries')->insert($payload);
                            }

                            if ($effectiveBaseline) {
                                $baselineUsed++;
                            }

                            continue;
                        }

                        $payload = $this->entryPayload($entryDefinition, (int) $topicId, $effectiveBaseline);
                        $payload['deleted_at'] = null;
                        $nextHash = $this->entryHash($payload, $topicDefinition['source_key']);

                        if ($currentHash === $nextHash && $entry->deleted_at === null) {
                            $result['entries_unchanged']++;
                        } else {
                            $result['entries_updated']++;
                            if (! $dryRun) {
                                DB::table('assistant_knowledge_entries')->where('id', $entry->id)->update([
                                    ...$payload,
                                    'source_hash' => $nextHash,
                                    'imported_at' => now(),
                                    'updated_at' => now(),
                                ]);
                            }
                        }

                        $baselineUsed += ($effectiveBaseline ? 1 : 0) - ($wasBaseline ? 1 : 0);
                    }
                }
            });
        } catch (Throwable) {
            // Query exceptions can contain bound values. Never expose the
            // curated knowledge text through console output or application logs.
            throw new RuntimeException('Der Standardwissensimport ist fehlgeschlagen und wurde vollständig zurückgerollt.');
        }

        if (! $dryRun) {
            $this->knowledge->forgetCache();
        }

        return $result;
    }

    /** @return array{entries_removed:int,topics_removed:int,customized_kept:int} */
    public function removePristineDefaults(): array
    {
        $this->assertSchemaReady();
        $keys = collect(AssistantKnowledgeDefaults::topics());
        $result = ['entries_removed' => 0, 'topics_removed' => 0, 'customized_kept' => 0];

        try {
            DB::transaction(function () use ($keys, &$result): void {
                foreach ($keys->flatMap(fn (array $topic): array => $topic['entries'])->pluck('source_key') as $sourceKey) {
                    $entry = DB::table('assistant_knowledge_entries')
                        ->where('source_key', $sourceKey)
                        ->lockForUpdate()
                        ->first();
                    if (! $entry) {
                        continue;
                    }

                    // A soft delete is an explicit editorial decision. Keep
                    // the tombstone during rollback instead of turning it into
                    // an irreversible physical deletion.
                    if ($entry->deleted_at !== null) {
                        $result['customized_kept']++;

                        continue;
                    }

                    $topicKey = (string) (DB::table('assistant_knowledge_topics')
                        ->where('id', $entry->topic_id)
                        ->value('source_key') ?? '');
                    $currentHash = $this->entryHash((array) $entry, $topicKey);

                    if (is_string($entry->source_hash) && hash_equals($entry->source_hash, $currentHash)) {
                        DB::table('assistant_knowledge_entries')->where('id', $entry->id)->delete();
                        $result['entries_removed']++;
                    } else {
                        $result['customized_kept']++;
                    }
                }

                foreach ($keys->pluck('source_key') as $sourceKey) {
                    $topic = DB::table('assistant_knowledge_topics')
                        ->where('source_key', $sourceKey)
                        ->lockForUpdate()
                        ->first();
                    if (
                        ! $topic
                        || $topic->deleted_at !== null
                        || DB::table('assistant_knowledge_entries')->where('topic_id', $topic->id)->exists()
                    ) {
                        if ($topic?->deleted_at !== null) {
                            $result['customized_kept']++;
                        }

                        continue;
                    }

                    $currentHash = $this->topicHash((array) $topic);
                    if (is_string($topic->source_hash) && hash_equals($topic->source_hash, $currentHash)) {
                        DB::table('assistant_knowledge_topics')->where('id', $topic->id)->delete();
                        $result['topics_removed']++;
                    } else {
                        $result['customized_kept']++;
                    }
                }
            });
        } catch (Throwable) {
            throw new RuntimeException('Das Zurücksetzen des Standardwissens ist fehlgeschlagen und wurde vollständig zurückgerollt.');
        }

        $this->knowledge->forgetCache();

        return $result;
    }

    /** @param array<string, mixed> $definition */
    private function topicPayload(array $definition): array
    {
        return [
            'source_key' => $definition['source_key'],
            'name' => $definition['name'],
            'description' => $definition['description'],
            'is_active' => true,
            'sort_order' => $definition['sort_order'],
        ];
    }

    /** @param array<string, mixed> $definition */
    private function entryPayload(array $definition, int $topicId, bool $includeInBaseline): array
    {
        return [
            'topic_id' => $topicId,
            'source_key' => $definition['source_key'],
            'source_url' => $definition['source_url'] ?? null,
            'title' => $definition['title'],
            'summary' => $definition['summary'],
            'content' => $definition['content'],
            'keywords' => json_encode($definition['keywords'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'is_active' => true,
            'include_in_baseline' => $includeInBaseline,
            'sort_order' => $definition['sort_order'],
        ];
    }

    /** @param array<string, mixed> $payload */
    private function topicHash(array $payload): string
    {
        return $this->hash([
            'source_key' => (string) ($payload['source_key'] ?? ''),
            'name' => (string) ($payload['name'] ?? ''),
            'description' => (string) ($payload['description'] ?? ''),
            'is_active' => (bool) ($payload['is_active'] ?? false),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);
    }

    /** @param array<string, mixed> $payload */
    private function entryHash(array $payload, string $topicSourceKey): string
    {
        $keywords = $payload['keywords'] ?? [];
        if (is_string($keywords)) {
            $keywords = json_decode($keywords, true) ?: [];
        }

        return $this->hash([
            'topic_source_key' => $topicSourceKey,
            'source_key' => (string) ($payload['source_key'] ?? ''),
            'source_url' => (string) ($payload['source_url'] ?? ''),
            'title' => (string) ($payload['title'] ?? ''),
            'summary' => (string) ($payload['summary'] ?? ''),
            'content' => (string) ($payload['content'] ?? ''),
            'keywords' => array_values((array) $keywords),
            'is_active' => (bool) ($payload['is_active'] ?? false),
            'include_in_baseline' => (bool) ($payload['include_in_baseline'] ?? false),
            'sort_order' => (int) ($payload['sort_order'] ?? 0),
        ]);
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** @param array<int, array<string, mixed>> $topics */
    private function validateManifest(array $topics): void
    {
        if ($topics === []) {
            throw new InvalidArgumentException('Das Standardwissen enthält keine Themen.');
        }

        $keys = [];
        $baselineCount = 0;

        foreach ($topics as $topic) {
            $this->assertUniqueKey($topic['source_key'] ?? null, $keys);
            $this->assertLength($topic['name'] ?? null, 2, 120, 'Thema');
            $this->assertLength($topic['description'] ?? '', 0, 500, 'Themenbeschreibung');
            $this->assertSortOrder($topic['sort_order'] ?? null);

            if (! is_array($topic['entries'] ?? null) || $topic['entries'] === []) {
                throw new InvalidArgumentException('Jedes Standardthema benötigt mindestens einen Eintrag.');
            }

            foreach ((array) ($topic['entries'] ?? []) as $entry) {
                $this->assertUniqueKey($entry['source_key'] ?? null, $keys);
                $this->assertLength($entry['title'] ?? null, 2, 180, 'Titel');
                $this->assertLength($entry['summary'] ?? '', 1, 1000, 'Kurzinfo');
                $this->assertLength($entry['content'] ?? null, 3, 50000, 'Inhalt');
                $this->assertSortOrder($entry['sort_order'] ?? null);

                if (! is_array($entry['keywords'] ?? null)) {
                    throw new InvalidArgumentException('Import-Schlagwörter müssen als Liste vorliegen.');
                }

                if (count($entry['keywords']) > 16) {
                    throw new InvalidArgumentException('Ein Standardeintrag darf höchstens 16 Schlagwörter enthalten.');
                }

                $normalizedKeywords = [];
                foreach ($entry['keywords'] as $keyword) {
                    $this->assertLength($keyword, 1, 80, 'Schlagwort');
                    $normalized = mb_strtolower(trim($keyword));
                    if (isset($normalizedKeywords[$normalized])) {
                        throw new InvalidArgumentException('Import-Schlagwörter müssen eindeutig sein.');
                    }

                    $normalizedKeywords[$normalized] = true;
                }

                if (
                    array_key_exists('source_url', $entry)
                    && $entry['source_url'] !== null
                    && ! AssistantKnowledgeDefaults::isAllowedSourceUrl($entry['source_url'])
                ) {
                    throw new InvalidArgumentException('Eine öffentliche Importquelle ist nicht freigegeben.');
                }

                if (! is_bool($entry['include_in_baseline'] ?? null)) {
                    throw new InvalidArgumentException('Die Basiswissen-Markierung muss eindeutig gesetzt sein.');
                }

                $baselineCount += $entry['include_in_baseline'] ? 1 : 0;
            }
        }

        if ($baselineCount > AssistantKnowledgePool::BASELINE_ENTRY_LIMIT) {
            throw new InvalidArgumentException('Das Standardwissen überschreitet das Basiskontext-Limit.');
        }
    }

    /** @param array<string, true> $seen */
    private function assertUniqueKey(mixed $key, array &$seen): void
    {
        if (! is_string($key) || ! preg_match('/\A[a-z0-9][a-z0-9._-]{2,159}\z/', $key) || isset($seen[$key])) {
            throw new InvalidArgumentException('Jeder Importdatensatz benötigt einen eindeutigen stabilen Quellschlüssel.');
        }

        $seen[$key] = true;
    }

    private function assertLength(mixed $value, int $minimum, int $maximum, string $label): void
    {
        if (
            ! is_string($value)
            || $value !== trim($value)
            || preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', $value) === 1
            || mb_strlen($value) < $minimum
            || mb_strlen($value) > $maximum
        ) {
            throw new InvalidArgumentException($label.' im Standardwissen ist ungültig.');
        }
    }

    private function assertSortOrder(mixed $value): void
    {
        if (! is_int($value) || $value < 0 || $value > 100000) {
            throw new InvalidArgumentException('Die Sortierung im Standardwissen ist ungültig.');
        }
    }

    private function assertSchemaReady(): void
    {
        if (
            ! Schema::hasColumns('assistant_knowledge_topics', ['source_key', 'source_hash', 'imported_at'])
            || ! Schema::hasColumns('assistant_knowledge_entries', ['source_key', 'source_url', 'source_hash', 'imported_at'])
        ) {
            throw new InvalidArgumentException('Bitte zuerst die aktuellen RailTime-Migrationen ausführen.');
        }
    }
}
