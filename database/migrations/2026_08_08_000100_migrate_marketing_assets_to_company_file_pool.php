<?php

use App\Enums\MarketingCreativeStatus;
use App\Models\FilePool;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const LEGACY_URL_PATTERN = '#/administrator/marketing/(?:medien|assets)/([0-9a-f-]{36})(?:\?v=[a-f0-9]+)?#i';

    private const FILE_URL_PATTERN = '#/administrator/marketing/dateien/([1-9][0-9]*)(?:\?v=[a-f0-9]+)?#i';

    public function up(): void
    {
        if (! Schema::hasTable('files') || ! Schema::hasTable('file_pools')) {
            return;
        }

        $this->preflightMarketingJson();
        $this->addFileMetadataColumns();
        $this->addApprovalDependencyColumn();
        $this->resetLegacyApprovalsWithoutDependencySnapshot();
        $poolId = $this->companyPoolId();

        if (Schema::hasTable('marketing_assets')) {
            $mapping = $this->importLegacyAssets($poolId, $this->importTargetFolderId($poolId));
            $this->rewriteMarketingContent($mapping, false);
        }

        if (Schema::hasTable('settings')) {
            $exists = DB::table('settings')
                ->where('type', 'marketing')
                ->where('key', 'file_source')
                ->exists();

            if (! $exists) {
                DB::table('settings')->insert([
                    'type' => 'marketing',
                    'key' => 'file_source',
                    'value' => json_encode(['selected_folder_id' => null], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('files')) {
            return;
        }

        $mapping = DB::table('files')
            ->whereNotNull('legacy_marketing_asset_public_id')
            ->pluck('legacy_marketing_asset_public_id', 'id')
            ->mapWithKeys(static fn (mixed $uuid, mixed $id): array => [(string) $id => (string) $uuid])
            ->all();

        $this->rewriteMarketingContent($mapping, true);

        if ($mapping !== []) {
            DB::table('files')->whereIn('id', array_map('intval', array_keys($mapping)))->delete();
        }

        if (Schema::hasIndex('files', 'files_legacy_marketing_asset_public_id_unique')) {
            Schema::table('files', function (Blueprint $table): void {
                $table->dropUnique('files_legacy_marketing_asset_public_id_unique');
            });
        }

        foreach ([
            'legacy_marketing_asset_public_id',
            'content_sha256',
            'image_width',
            'image_height',
        ] as $column) {
            if (Schema::hasColumn('files', $column)) {
                Schema::table('files', function (Blueprint $table) use ($column): void {
                    $table->dropColumn($column);
                });
            }
        }

        if (Schema::hasTable('marketing_creatives') && Schema::hasColumn('marketing_creatives', 'approval_dependency_hash')) {
            Schema::table('marketing_creatives', function (Blueprint $table): void {
                $table->dropColumn('approval_dependency_hash');
            });
        }
    }

    private function addFileMetadataColumns(): void
    {
        if (! Schema::hasColumn('files', 'legacy_marketing_asset_public_id')) {
            Schema::table('files', function (Blueprint $table): void {
                $table->uuid('legacy_marketing_asset_public_id')->nullable();
            });
        }
        if (! Schema::hasColumn('files', 'content_sha256')) {
            Schema::table('files', function (Blueprint $table): void {
                $table->char('content_sha256', 64)->nullable();
            });
        }
        if (! Schema::hasColumn('files', 'image_width')) {
            Schema::table('files', function (Blueprint $table): void {
                $table->unsignedInteger('image_width')->nullable();
            });
        }
        if (! Schema::hasColumn('files', 'image_height')) {
            Schema::table('files', function (Blueprint $table): void {
                $table->unsignedInteger('image_height')->nullable();
            });
        }
        if (! Schema::hasIndex('files', 'files_legacy_marketing_asset_public_id_unique')) {
            Schema::table('files', function (Blueprint $table): void {
                $table->unique('legacy_marketing_asset_public_id', 'files_legacy_marketing_asset_public_id_unique');
            });
        }
    }

    private function preflightMarketingJson(): void
    {
        if (Schema::hasTable('marketing_creative_variants')) {
            DB::table('marketing_creative_variants')
                ->select(['id', 'builder_data'])
                ->orderBy('id')
                ->chunkById(250, function ($variants): void {
                    foreach ($variants as $variant) {
                        $this->decodeJsonObject(
                            (string) $variant->builder_data,
                            'marketing_creative_variants.builder_data#'.$variant->id,
                        );
                    }
                });
        }

        if (Schema::hasTable('marketing_creatives')) {
            DB::table('marketing_creatives')
                ->select(['id', 'shared_content'])
                ->orderBy('id')
                ->chunkById(250, function ($creatives): void {
                    foreach ($creatives as $creative) {
                        $this->decodeJsonObject(
                            (string) $creative->shared_content,
                            'marketing_creatives.shared_content#'.$creative->id,
                        );
                    }
                });
        }
    }

    private function addApprovalDependencyColumn(): void
    {
        if (! Schema::hasTable('marketing_creatives') || Schema::hasColumn('marketing_creatives', 'approval_dependency_hash')) {
            return;
        }

        Schema::table('marketing_creatives', function (Blueprint $table): void {
            $table->char('approval_dependency_hash', 64)->nullable()->after('approved_at');
        });
    }

    private function resetLegacyApprovalsWithoutDependencySnapshot(): void
    {
        if (! Schema::hasTable('marketing_creatives') || ! Schema::hasColumn('marketing_creatives', 'approval_dependency_hash')) {
            return;
        }

        DB::table('marketing_creatives')
            ->where('status', MarketingCreativeStatus::Approved->value)
            ->whereNull('approval_dependency_hash')
            ->update([
                'status' => MarketingCreativeStatus::Draft->value,
                'approved_by' => null,
                'approved_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function companyPoolId(): int
    {
        $poolId = DB::table('file_pools')
            ->where('filepoolable_type', 'company')
            ->where('filepoolable_id', 0)
            ->value('id');

        if ($poolId) {
            return (int) $poolId;
        }

        return (int) DB::table('file_pools')->insertGetId([
            'filepoolable_type' => 'company',
            'filepoolable_id' => 0,
            'title' => 'Firmendateien',
            'type' => 'company',
            'description' => '',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function importTargetFolderId(int $poolId): ?int
    {
        if (! Schema::hasTable('settings') || ! Schema::hasTable('file_folders')) {
            return null;
        }

        $stored = DB::table('settings')
            ->where('type', 'marketing')
            ->where('key', 'file_source')
            ->value('value');
        if (! is_string($stored)) {
            return null;
        }

        try {
            $decoded = json_decode($stored, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)
            || ! array_key_exists('selected_folder_id', $decoded)
            || $decoded['selected_folder_id'] === null) {
            return null;
        }

        $folderId = filter_var($decoded['selected_folder_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (! is_int($folderId)) {
            return null;
        }

        $visited = [];
        $currentId = $folderId;
        for ($depth = 0; $depth < 250; $depth++) {
            if (isset($visited[$currentId])) {
                return null;
            }
            $visited[$currentId] = true;

            $folder = DB::table('file_folders')->where('id', $currentId)->first();
            if (! $folder || (int) $folder->file_pool_id !== $poolId) {
                return null;
            }

            if ($folder->parent_id === null) {
                return $folderId;
            }

            $currentId = (int) $folder->parent_id;
        }

        return null;
    }

    /** @return array<string, int> */
    private function importLegacyAssets(int $poolId, ?int $folderId): array
    {
        $mapping = [];

        DB::table('marketing_assets')->whereNull('deleted_at')->orderBy('id')->chunkById(100, function ($assets) use ($poolId, $folderId, &$mapping): void {
            foreach ($assets as $asset) {
                $existing = DB::table('files')
                    ->where('legacy_marketing_asset_public_id', $asset->public_id)
                    ->value('id');

                $fileId = $existing ?: DB::table('files')->insertGetId([
                    'fileable_type' => (new FilePool)->getMorphClass(),
                    'fileable_id' => $poolId,
                    'filepool_id' => $poolId,
                    'folder_id' => $folderId,
                    'user_id' => $asset->created_by,
                    'name' => $asset->original_name,
                    'path' => $asset->path,
                    'disk' => $asset->disk,
                    'mime_type' => $asset->mime_type,
                    'type' => 'marketing-image',
                    'shared_roles' => null,
                    'size' => $asset->size,
                    'expires_at' => null,
                    'visible_from' => null,
                    'auto_delete' => false,
                    'visible_teams' => null,
                    'legacy_marketing_asset_public_id' => $asset->public_id,
                    'content_sha256' => $asset->sha256,
                    'image_width' => $asset->width,
                    'image_height' => $asset->height,
                    'created_at' => $asset->created_at,
                    'updated_at' => $asset->updated_at,
                ]);

                $mapping[strtolower((string) $asset->public_id)] = (int) $fileId;
            }
        });

        return $mapping;
    }

    /** @param array<string, int>|array<string, string> $mapping */
    private function rewriteMarketingContent(array $mapping, bool $reverse): void
    {
        if ($mapping === [] || ! Schema::hasTable('marketing_creative_variants')) {
            return;
        }

        DB::table('marketing_creative_variants')->orderBy('id')->chunkById(100, function ($variants) use ($mapping, $reverse): void {
            foreach ($variants as $variant) {
                $builderData = $this->decodeJsonObject((string) $variant->builder_data, 'marketing_creative_variants.builder_data#'.$variant->id);
                $nextBuilderData = $this->rewriteValue($builderData, $mapping, $reverse);
                $nextHtml = $this->rewriteString((string) $variant->html, $mapping, $reverse);
                $nextCss = $this->rewriteString((string) $variant->css, $mapping, $reverse);

                if ($nextBuilderData === $builderData && $nextHtml === $variant->html && $nextCss === $variant->css) {
                    continue;
                }

                DB::table('marketing_creative_variants')->where('id', $variant->id)->update([
                    'builder_data' => json_encode($nextBuilderData, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'html' => $nextHtml,
                    'css' => $nextCss,
                    'content_hash' => $this->contentHash($nextBuilderData, $nextHtml, $nextCss),
                    'version' => (int) $variant->version + 1,
                    'updated_at' => now(),
                ]);

                $this->resetCreativeApproval((int) $variant->marketing_creative_id);
            }
        });

        if (! Schema::hasTable('marketing_creatives')) {
            return;
        }

        DB::table('marketing_creatives')->orderBy('id')->chunkById(100, function ($creatives) use ($mapping, $reverse): void {
            foreach ($creatives as $creative) {
                $content = $this->decodeJsonObject((string) $creative->shared_content, 'marketing_creatives.shared_content#'.$creative->id);
                $rewritten = $this->rewriteValue($content, $mapping, $reverse);
                if ($rewritten === $content) {
                    continue;
                }

                DB::table('marketing_creatives')->where('id', $creative->id)->update([
                    'shared_content' => json_encode($rewritten, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                    'status' => MarketingCreativeStatus::Draft->value,
                    'approved_by' => null,
                    'approved_at' => null,
                    'approval_dependency_hash' => null,
                    'updated_at' => now(),
                ]);
            }
        });
    }

    /** @return array<string, mixed> */
    private function decodeJsonObject(string $json, string $context): array
    {
        try {
            $decoded = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new \RuntimeException('Ungültiges JSON in '.$context.'; die Marketing-Migration wurde ohne Datenänderung abgebrochen.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new \RuntimeException('Unerwartete JSON-Struktur in '.$context.'; die Marketing-Migration wurde ohne Datenänderung abgebrochen.');
        }

        return $decoded;
    }

    private function resetCreativeApproval(int $creativeId): void
    {
        DB::table('marketing_creatives')->where('id', $creativeId)->update([
            'status' => MarketingCreativeStatus::Draft->value,
            'approved_by' => null,
            'approved_at' => null,
            'approval_dependency_hash' => null,
            'updated_at' => now(),
        ]);
    }

    private function rewriteValue(mixed $value, array $mapping, bool $reverse): mixed
    {
        if (is_string($value)) {
            return $this->rewriteString($value, $mapping, $reverse);
        }

        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->rewriteValue($nested, $mapping, $reverse);
        }

        return $value;
    }

    private function rewriteString(string $value, array $mapping, bool $reverse): string
    {
        if ($reverse) {
            return preg_replace_callback(self::FILE_URL_PATTERN, static function (array $match) use ($mapping): string {
                $uuid = $mapping[(string) $match[1]] ?? null;

                return $uuid ? '/administrator/marketing/medien/'.$uuid : $match[0];
            }, $value) ?? $value;
        }

        return preg_replace_callback(self::LEGACY_URL_PATTERN, static function (array $match) use ($mapping): string {
            $fileId = $mapping[strtolower($match[1])] ?? null;

            return $fileId ? '/administrator/marketing/dateien/'.$fileId : $match[0];
        }, $value) ?? $value;
    }

    /** @param array<string, mixed> $builderData */
    private function contentHash(array $builderData, string $html, string $css): string
    {
        return hash('sha256', json_encode([
            'builder_data' => $this->sortRecursively($builderData),
            'html' => $html,
            'css' => $css,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private function sortRecursively(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $nested) {
            $value[$key] = $this->sortRecursively($nested);
        }

        return $value;
    }

};
