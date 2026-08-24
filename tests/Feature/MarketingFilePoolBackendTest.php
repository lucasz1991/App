<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Enums\MarketingRenderStatus;
use App\Livewire\Admin\Marketing\CreativesIndex;
use App\Models\File;
use App\Models\FileFolder;
use App\Models\FilePool;
use App\Models\Setting;
use App\Models\User;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Marketing\MarketingHtmlSanitizer;
use App\Services\Marketing\MarketingRenderAssetHydrator;
use App\Services\Marketing\MarketingRenderService;
use App\Services\Marketing\MarketingStudioService;
use App\Support\MarketingFileSourceSettings;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Mockery;
use Tests\TestCase;

class MarketingFilePoolBackendTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
        config()->set('app.url', 'https://railtime.test');
    }

    public function test_selected_company_folder_is_enforced_for_listing_streaming_and_url_parsing(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $pool = FilePool::company();
        $selected = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Marketing']);
        $child = FileFolder::query()->create([
            'file_pool_id' => $pool->id,
            'parent_id' => $selected->id,
            'name' => 'Jobs',
        ]);
        $outside = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Intern']);
        $allowed = $this->createImageFile($pool, $child, $admin, 'allowed.png', [20, 12, 0x17324D]);
        $blocked = $this->createImageFile($pool, $outside, $admin, 'blocked.png', [20, 12, 0x981B2D]);
        $publicDisk = $pool->files()->create([
            'folder_id' => $child->id,
            'user_id' => $admin->id,
            'name' => 'public.png',
            'path' => 'uploads/files/public.png',
            'disk' => 'public',
            'mime_type' => 'image/png',
            'size' => 68,
        ]);
        $files = app(MarketingFileSourceService::class);

        $files->setSelectedFolder($selected->id, $admin);
        $assets = $files->editorAssets();

        $this->assertCount(1, $assets);
        $this->assertStringContainsString('/administrator/marketing/dateien/'.$allowed->id, $assets[0]['src']);
        $this->assertTrue($files->urlIsAllowed('/administrator/marketing/dateien/'.$allowed->id));
        $this->assertFalse($files->urlIsAllowed('/administrator/marketing/dateien/'.$blocked->id));
        $this->assertFalse($files->urlIsAllowed('/administrator/marketing/dateien/'.$publicDisk->id));

        $allowedUrl = route('admin.marketing.files.show', $allowed).'?v='.substr($assets[0]['src'], -16);
        $response = $this->actingAs($admin)->get($allowedUrl)->assertOk();
        $etag = $response->headers->get('ETag');
        $this->assertNotNull($etag);
        $this->assertStringContainsString('private', (string) $response->headers->get('Cache-Control'));
        $this->assertStringContainsString('must-revalidate', (string) $response->headers->get('Cache-Control'));
        $this->assertSame('same-origin', $response->headers->get('Cross-Origin-Resource-Policy'));
        $this->actingAs($admin)->withHeader('If-None-Match', $etag)->get($allowedUrl)->assertNotModified();
        $this->actingAs($admin)->get(route('admin.marketing.files.show', $blocked))->assertNotFound();
        $this->actingAs($admin)->get(route('admin.marketing.files.show', $publicDisk))->assertNotFound();
        $this->actingAs($staff)->get(route('admin.marketing.files.show', $allowed))->assertForbidden();

        $relative = '/administrator/marketing/dateien/'.$allowed->id;
        $this->assertSame($allowed->id, $files->fileIdFromUrl($relative));
        $this->assertSame($allowed->id, $files->fileIdFromUrl('https://railtime.test'.$relative));
        $this->assertNull($files->fileIdFromUrl('https://railtime.test.evil'.$relative));
        $this->assertNull($files->fileIdFromUrl('https://attacker@railtime.test'.$relative));
        $this->assertNull($files->fileIdFromUrl('javascript:'.$relative));
        $this->assertNull($files->fileIdFromUrl('https://railtime.test'.$relative.'.png'));
        $this->assertNull($files->fileIdFromUrl('https://railtime.test'.$relative.'#fragment'));
        $this->assertContains(
            'throttle:600,1',
            app('router')->getRoutes()->getByName('admin.marketing.files.show')->gatherMiddleware(),
        );
    }

    public function test_invalid_source_and_stale_source_save_fail_closed_without_broadening_to_root(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $folder = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Marketing']);
        $this->createImageFile($pool, null, $admin, 'root.png', [12, 12, 0x17324D]);
        $files = app(MarketingFileSourceService::class);
        $oldFingerprint = $files->selectionFingerprint();

        $files->setSelectedFolder($folder->id, $admin, $oldFingerprint);
        $this->expectException(ValidationException::class);
        $files->setSelectedFolder(null, $admin, $oldFingerprint);
    }

    public function test_editor_library_has_a_visible_honest_limit_and_total(): void
    {
        config()->set('marketing.assets.editor_limit', 1);
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $this->createImageFile($pool, null, $admin, 'eins.png', [12, 12, 0x17324D]);
        $this->createImageFile($pool, null, $admin, 'zwei.png', [12, 12, 0x981B2D]);

        $library = app(MarketingFileSourceService::class)->editorAssetLibrary();

        $this->assertCount(1, $library['assets']);
        $this->assertSame(2, $library['total']);
        $this->assertSame(1, $library['limit']);
        $this->assertTrue($library['truncated']);
        $this->assertSame('image/png', $library['assets'][0]['mime_type']);
        $this->assertFalse($library['assets'][0]['animated']);
    }

    public function test_motive_index_uses_metadata_summary_without_opening_private_blobs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $pool->files()->create([
            'user_id' => $admin->id,
            'name' => 'nur-metadaten.png',
            'path' => 'uploads/files/nicht-vorhanden.png',
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => 68,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('1 Bild im Editor verfügbar');

        $this->assertFalse(Storage::disk('private')->exists('uploads/files/nicht-vorhanden.png'));
    }

    public function test_editor_library_exposes_verified_gif_metadata_for_opaque_file_routes(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $contents = (string) file_get_contents(resource_path('mail-templates/assets/zug-dampf-light.gif'));
        $path = 'uploads/files/'.uniqid('marketing-gif-', true).'-zug.gif';
        Storage::disk('private')->put($path, $contents);
        $pool->files()->create([
            'user_id' => $admin->id,
            'name' => 'zug-animation.gif',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'image/gif',
            'size' => strlen($contents),
        ]);

        $asset = app(MarketingFileSourceService::class)->editorAssetLibrary()['assets'][0];

        $this->assertStringContainsString('/administrator/marketing/dateien/', $asset['src']);
        $this->assertSame('image/gif', $asset['mime_type']);
        $this->assertTrue($asset['animated']);
    }

    public function test_livewire_source_save_uses_compare_and_swap_and_refreshes_after_conflict(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $first = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Erste Quelle']);
        $second = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Zweite Quelle']);
        $firstEditor = Livewire::actingAs($admin)->test(CreativesIndex::class);
        $staleEditor = Livewire::actingAs($admin)->test(CreativesIndex::class);

        $firstEditor
            ->set('mediaFolderId', (string) $first->id)
            ->call('saveMediaFolder')
            ->assertHasNoErrors();
        $staleEditor
            ->set('mediaFolderId', (string) $second->id)
            ->call('saveMediaFolder')
            ->assertHasErrors(['mediaFolderId'])
            ->assertSet('mediaFolderId', (string) $first->id);

        $this->assertSame($first->id, app(MarketingFileSourceService::class)->selectedFolderId(true));
    }

    public function test_malformed_or_deleted_source_never_exposes_root_files(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $this->createImageFile($pool, null, $admin, 'root.png', [12, 12, 0x17324D]);

        Setting::query()
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->update(['value' => json_encode(['selected_folder_id' => 999999], JSON_THROW_ON_ERROR)]);
        $files = app(MarketingFileSourceService::class);
        $this->assertTrue($files->hasInvalidSelection());
        $this->assertSame([], $files->editorAssets());

        Setting::query()
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->update(['value' => json_encode(['unexpected' => null], JSON_THROW_ON_ERROR)]);
        $this->assertSame(0, $files->selectedFolderId(uncached: true));
        $this->assertSame([], $files->editorAssets());
    }

    public function test_setting_distinguishes_missing_explicit_root_sql_null_and_malformed_json(): void
    {
        DB::table('settings')
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->delete();
        $this->assertNull(MarketingFileSourceSettings::selectedFolderId());
        $this->assertNull(MarketingFileSourceSettings::selectedFolderId(true));

        DB::table('settings')->insert([
            'type' => MarketingFileSourceSettings::GROUP,
            'key' => MarketingFileSourceSettings::KEY,
            'value' => json_encode(['selected_folder_id' => null], JSON_THROW_ON_ERROR),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->assertNull(MarketingFileSourceSettings::selectedFolderId());
        $this->assertNull(MarketingFileSourceSettings::selectedFolderId(true));

        DB::table('settings')
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->update(['value' => null]);
        $this->assertSame(0, MarketingFileSourceSettings::selectedFolderId());
        $this->assertSame(0, MarketingFileSourceSettings::selectedFolderId(true));

        DB::table('settings')
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->update(['value' => 'null']);
        $this->assertSame(0, MarketingFileSourceSettings::selectedFolderId());
        $this->assertSame(0, MarketingFileSourceSettings::selectedFolderId(true));

        DB::table('settings')
            ->where('type', MarketingFileSourceSettings::GROUP)
            ->where('key', MarketingFileSourceSettings::KEY)
            ->update(['value' => '{broken']);
        $this->assertSame(0, MarketingFileSourceSettings::selectedFolderId());
        $this->assertSame(0, MarketingFileSourceSettings::selectedFolderId(true));
    }

    public function test_approval_is_bound_to_current_private_file_bytes_and_byte_change_demotes_before_queue(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $file = $this->createImageFile($pool, null, $admin, 'einsatz.png', [32, 20, 0x17324D]);
        $studio = app(MarketingStudioService::class);
        $renderer = app(MarketingRenderService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $variant = $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Story,
            $variant->builder_data,
            str_replace('/rt-brand/img/wagenmeister-team-gleis.jpeg', '/administrator/marketing/dateien/'.$file->id, $variant->html),
            $variant->css,
            $variant->content_hash,
            $admin,
        );
        $approved = $studio->approve($creative->fresh(), $admin);
        $this->assertSame(MarketingCreativeStatus::Approved, $approved->status);
        $this->assertSame(64, strlen((string) $approved->approval_dependency_hash));
        $before = $renderer->fingerprint($approved, $variant->fresh());

        $changed = $this->png(32, 20, 0x981B2D);
        Storage::disk('private')->put($file->path, $changed);
        DB::table('files')->where('id', $file->id)->update([
            'size' => strlen($changed),
            'content_sha256' => null,
            'updated_at' => now(),
        ]);
        $changedDependencyHash = app(MarketingRenderAssetHydrator::class)
            ->creativeApprovalFingerprint($creative->fresh()->variants()->get());
        $this->assertNotSame($approved->approval_dependency_hash, $changedDependencyHash);

        $render = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Story, $admin);
        $after = $creative->fresh();
        $this->assertSame(MarketingCreativeStatus::Draft, $after->status);
        $this->assertNull($after->approval_dependency_hash);
        $this->assertNotSame($before, $render->fingerprint);
        $this->assertTrue($renderer->requiresWatermark($after));
    }

    public function test_post_chromium_file_change_fails_finalize_and_stale_download_is_denied(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $file = $this->createImageFile($pool, null, $admin, 'einsatz.png', [30, 18, 0x17324D]);
        $studio = app(MarketingStudioService::class);
        $renderer = app(MarketingRenderService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Info, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Web);
        $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Web,
            $variant->builder_data,
            str_replace('/rt-brand/img/wagenmeister-team.webp', '/administrator/marketing/dateien/'.$file->id, $variant->html),
            $variant->css,
            $variant->content_hash,
            $admin,
        );
        $studio->approve($creative->fresh(), $admin);
        $render = $renderer->queue($creative->fresh(), MarketingCreativeFormat::Web, $admin);

        Process::fake(function ($process) use ($file): string {
            $outputIndex = array_search('--output', $process->command, true);
            $this->writePng($process->command[$outputIndex + 1], 1200, 630, 0x17324D);
            $changed = $this->png(30, 18, 0x981B2D);
            Storage::disk('private')->put($file->path, $changed);
            DB::table('files')->where('id', $file->id)->update([
                'size' => strlen($changed),
                'content_sha256' => null,
                'updated_at' => now(),
            ]);

            return json_encode(['ok' => true], JSON_THROW_ON_ERROR);
        });

        $failed = $renderer->render($render);
        $this->assertSame(MarketingRenderStatus::Failed, $failed->status);
        $this->assertNull($failed->path);
        $this->assertFalse($renderer->isCurrent($failed));
        $this->actingAs($admin)
            ->get(route('admin.marketing.renders.download', $failed))
            ->assertStatus(409);
    }

    public function test_referenced_files_and_source_folders_are_guarded_but_forced_purge_invalidates_dependants(): void
    {
        Queue::fake();
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $source = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Marketing']);
        $child = FileFolder::query()->create([
            'file_pool_id' => $pool->id,
            'parent_id' => $source->id,
            'name' => 'Jobs',
        ]);
        $outside = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Intern']);
        $file = $this->createImageFile($pool, $child, $admin, 'job.png', [20, 20, 0x17324D]);
        $files = app(MarketingFileSourceService::class);
        $files->setSelectedFolder($source->id, $admin);
        $studio = app(MarketingStudioService::class);
        $creative = $studio->createFromTemplate(MarketingCreativeType::Job, $admin);
        $variant = $creative->variants->firstWhere('format', MarketingCreativeFormat::Post);
        $studio->saveVariant(
            $creative,
            MarketingCreativeFormat::Post,
            $variant->builder_data,
            str_replace('/rt-brand/img/wagenmeister-team-gleis.jpeg', '/administrator/marketing/dateien/'.$file->id, $variant->html),
            $variant->css,
            $variant->content_hash,
            $admin,
        );
        $studio->approve($creative->fresh(), $admin);

        try {
            DB::transaction(static fn () => $file->delete());
            $this->fail('Eine referenzierte Datei durfte gelöscht werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('file', $exception->errors());
        }
        try {
            DB::transaction(static fn () => $file->update(['folder_id' => $outside->id]));
            $this->fail('Eine referenzierte Datei durfte die Quelle nicht verlassen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('folder', $exception->errors());
        }
        try {
            $source->deleteRecursive();
            $this->fail('Der Quellordner durfte nicht rekursiv gelöscht werden.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('folder', $exception->errors());
        }
        $this->assertDatabaseHas('files', ['id' => $file->id]);
        $this->assertDatabaseHas('file_folders', ['id' => $child->id]);

        DB::table('files')->where('id', $file->id)->update([
            'auto_delete' => true,
            'expires_at' => now()->subMinute(),
        ]);
        $this->artisan('files:purge-expired')->assertSuccessful();
        $this->assertDatabaseMissing('files', ['id' => $file->id]);
        $this->assertSame(MarketingCreativeStatus::Draft, $creative->fresh()->status);
        $this->assertNull($creative->fresh()->approval_dependency_hash);
    }

    public function test_file_delete_commit_survives_an_invalid_storage_adapter(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $file = $pool->files()->create([
            'user_id' => $admin->id,
            'name' => 'verwaist.png',
            'path' => 'uploads/files/verwaist.png',
            'disk' => 'nicht-konfiguriert',
            'mime_type' => 'image/png',
            'size' => 68,
        ]);

        DB::transaction(static fn (): bool => $file->delete());

        $this->assertDatabaseMissing('files', ['id' => $file->id]);
    }

    public function test_folder_breadcrumb_stops_at_cycles_and_foreign_pools(): void
    {
        $pool = FilePool::company();
        $first = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'A']);
        $second = FileFolder::query()->create([
            'file_pool_id' => $pool->id,
            'parent_id' => $first->id,
            'name' => 'B',
        ]);

        DB::table('file_folders')->where('id', $first->id)->update(['parent_id' => $second->id]);
        $cycle = $first->fresh()->breadcrumb();
        $this->assertCount(2, $cycle);
        $this->assertSame([$second->id, $first->id], array_map(static fn (FileFolder $folder): int => $folder->id, $cycle));

        $foreignPool = FilePool::query()->create([
            'title' => 'Fremder Pool',
            'type' => 'user',
            'description' => '',
            'filepoolable_type' => User::class,
            'filepoolable_id' => User::factory()->create()->id,
        ]);
        $foreign = FileFolder::query()->create(['file_pool_id' => $foreignPool->id, 'name' => 'Fremd']);
        DB::table('file_folders')->where('id', $first->id)->update(['parent_id' => $foreign->id]);

        $this->assertSame([$first->id], array_map(
            static fn (FileFolder $folder): int => $folder->id,
            $first->fresh()->breadcrumb(),
        ));
    }

    public function test_oversized_central_file_is_rejected_from_metadata_without_get_or_stream(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $file = $pool->files()->create([
            'user_id' => $admin->id,
            'name' => 'riesig.png',
            'path' => 'uploads/files/riesig.png',
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => 9 * 1024 * 1024,
        ]);
        config()->set('marketing.assets.max_kilobytes', 8192);
        $disk = Mockery::mock();
        $disk->shouldNotReceive('exists');
        $disk->shouldNotReceive('size');
        $disk->shouldNotReceive('get');
        $disk->shouldNotReceive('readStream');
        app('filesystem')->set('private', $disk);

        $this->expectException(ValidationException::class);
        app(MarketingFileSourceService::class)->validatedSnapshot($file);
    }

    public function test_oversized_storage_blob_is_rejected_before_any_content_read(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = FilePool::company()->files()->create([
            'user_id' => $admin->id,
            'name' => 'unbekannt-gross.png',
            'path' => 'uploads/files/unbekannt-gross.png',
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => null,
        ]);
        config()->set('marketing.assets.max_kilobytes', 8192);
        $disk = Mockery::mock();
        $disk->shouldReceive('exists')->once()->with($file->path)->andReturnTrue();
        $disk->shouldReceive('size')->once()->with($file->path)->andReturn(9 * 1024 * 1024);
        $disk->shouldNotReceive('get');
        $disk->shouldNotReceive('readStream');
        app('filesystem')->set('private', $disk);

        $this->expectException(ValidationException::class);
        app(MarketingFileSourceService::class)->validatedSnapshot($file);
    }

    public function test_sanitizer_rejects_external_lookalikes_fragments_and_malformed_css_urls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $file = $this->createImageFile(FilePool::company(), null, $admin, 'safe.png', [10, 10, 0x17324D]);
        $path = '/administrator/marketing/dateien/'.$file->id;
        $sanitizer = app(MarketingHtmlSanitizer::class);
        $html = $sanitizer->html(
            '<img src="https://railtime.test'.$path.'">'
            .'<img src="https://railtime.test.evil'.$path.'">'
            .'<img src="https://attacker@railtime.test'.$path.'">'
            .'<img src="javascript:'.$path.'">'
            .'<img src="https://railtime.test/rt-brand/img/logo-horizontal.png#bad">',
        );
        $css = $sanitizer->css('.bad{background:url(https://evil.test/pixel.png}.safe{color:red}');

        $this->assertStringContainsString('https://railtime.test'.$path, $html);
        $this->assertStringNotContainsString('railtime.test.evil', $html);
        $this->assertStringNotContainsString('attacker@', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('#bad', $html);
        $this->assertStringNotContainsString('evil.test', $css);
    }

    public function test_migration_preflights_malformed_setting_before_ddl(): void
    {
        $migration = require database_path('migrations/2026_08_08_000100_migrate_marketing_assets_to_company_file_pool.php');
        $migration->down();
        DB::table('settings')->updateOrInsert(
            ['type' => 'marketing', 'key' => 'file_source'],
            ['value' => '{broken', 'created_at' => now(), 'updated_at' => now()],
        );

        try {
            $migration->up();
            $this->fail('Malformed settings must abort before DDL.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('settings.marketing.file_source', $exception->getMessage());
        }

        $this->assertFalse(Schema::hasColumn('files', 'content_sha256'));
        $this->assertFalse(Schema::hasColumn('marketing_creatives', 'approval_dependency_hash'));
    }

    public function test_migration_imports_only_active_legacy_assets_rewrites_nested_content_and_rolls_back(): void
    {
        $migration = require database_path('migrations/2026_08_08_000100_migrate_marketing_assets_to_company_file_pool.php');
        $migration->down();

        $admin = User::factory()->create(['role' => 'admin']);
        $pool = FilePool::company();
        $folder = FileFolder::query()->create(['file_pool_id' => $pool->id, 'name' => 'Import']);
        DB::table('settings')->updateOrInsert(
            ['type' => 'marketing', 'key' => 'file_source'],
            [
                'value' => json_encode(['selected_folder_id' => $folder->id], JSON_THROW_ON_ERROR),
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
        $activeUuid = '10000000-0000-4000-8000-000000000001';
        $deletedUuid = '20000000-0000-4000-8000-000000000002';
        foreach ([[$activeUuid, null], [$deletedUuid, now()]] as [$uuid, $deletedAt]) {
            DB::table('marketing_assets')->insert([
                'public_id' => $uuid,
                'original_name' => $uuid.'.png',
                'disk' => 'private',
                'path' => 'marketing/assets/'.$uuid.'.png',
                'mime_type' => 'image/png',
                'extension' => 'png',
                'size' => 68,
                'width' => 1,
                'height' => 1,
                'sha256' => str_repeat($uuid === $activeUuid ? 'a' : 'b', 64),
                'created_by' => $admin->id,
                'created_at' => now(),
                'updated_at' => now(),
                'deleted_at' => $deletedAt,
            ]);
        }
        $creativeId = DB::table('marketing_creatives')->insertGetId([
            'public_id' => '30000000-0000-4000-8000-000000000003',
            'type' => 'job',
            'status' => 'approved',
            'title' => 'Legacy',
            'shared_content' => json_encode(['nested' => ['image' => '/administrator/marketing/medien/'.$activeUuid]], JSON_THROW_ON_ERROR),
            'approved_by' => $admin->id,
            'approved_at' => now(),
            'created_by' => $admin->id,
            'updated_by' => $admin->id,
            'created_at' => now(),
            'updated_at' => now(),
            'deleted_at' => null,
        ]);
        DB::table('marketing_creative_variants')->insert([
            'marketing_creative_id' => $creativeId,
            'format' => 'story',
            'builder_data' => json_encode(['pages' => [['component' => '<img src="/administrator/marketing/assets/'.$activeUuid.'">']]], JSON_THROW_ON_ERROR),
            'html' => '<img src="/administrator/marketing/medien/'.$activeUuid.'"><img src="/administrator/marketing/medien/'.$deletedUuid.'">',
            'css' => '.hero{background:url("/administrator/marketing/assets/'.$activeUuid.'")}',
            'content_hash' => str_repeat('c', 64),
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $imported = DB::table('files')->where('legacy_marketing_asset_public_id', $activeUuid)->first();
        $this->assertNotNull($imported);
        $this->assertSame($folder->id, (int) $imported->folder_id);
        $this->assertFalse(DB::table('files')->where('legacy_marketing_asset_public_id', $deletedUuid)->exists());
        $this->assertSame(2, DB::table('marketing_assets')->count());
        $variant = DB::table('marketing_creative_variants')->where('marketing_creative_id', $creativeId)->first();
        $this->assertStringContainsString('/administrator/marketing/dateien/'.$imported->id, $variant->html.$variant->css.$variant->builder_data);
        $this->assertStringContainsString('/administrator/marketing/medien/'.$deletedUuid, $variant->html);
        $this->assertSame('draft', DB::table('marketing_creatives')->where('id', $creativeId)->value('status'));

        $migration->down();
        $rolledBack = DB::table('marketing_creative_variants')->where('marketing_creative_id', $creativeId)->first();
        $this->assertStringContainsString('/administrator/marketing/medien/'.$activeUuid, $rolledBack->html.$rolledBack->css.$rolledBack->builder_data);
        $this->assertFalse(DB::table('files')->where('id', $imported->id)->exists());
        $this->assertSame(2, DB::table('marketing_assets')->count());
    }

    private function createImageFile(
        FilePool $pool,
        ?FileFolder $folder,
        User $actor,
        string $name,
        array $image,
    ): File {
        [$width, $height, $color] = $image;
        $contents = $this->png($width, $height, $color);
        $path = 'uploads/files/'.uniqid('marketing-', true).'-'.$name;
        Storage::disk('private')->put($path, $contents);

        return $pool->files()->create([
            'folder_id' => $folder?->id,
            'user_id' => $actor->id,
            'name' => $name,
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => strlen($contents),
        ]);
    }

    private function png(int $width, int $height, int $color): string
    {
        $image = imagecreatetruecolor($width, $height);
        imagefill($image, 0, 0, $color);
        ob_start();
        imagepng($image);
        $contents = (string) ob_get_clean();
        imagedestroy($image);

        return $contents;
    }

    private function writePng(string $path, int $width, int $height, int $color): void
    {
        file_put_contents($path, $this->png($width, $height, $color));
    }
}
