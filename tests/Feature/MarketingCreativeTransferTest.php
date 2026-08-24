<?php

namespace Tests\Feature;

use App\Enums\MarketingCreativeFormat;
use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\File;
use App\Models\FilePool;
use App\Models\MarketingCreative;
use App\Models\User;
use App\Services\Marketing\MarketingContentBinder;
use App\Services\Marketing\MarketingCreativeTransferService;
use App\Services\Marketing\MarketingFileSourceService;
use App\Services\Marketing\MarketingStudioService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

final class MarketingCreativeTransferTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        config()->set('marketing.disk', 'private');
    }

    public function test_export_import_roundtrip_embeds_private_images_and_always_creates_a_new_draft(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $sourceFile = $this->attachPrivateImage($creative);
        $sourceHtml = $creative->variants()->where('format', MarketingCreativeFormat::Story->value)->value('html');
        $this->assertStringContainsString('/administrator/marketing/dateien/'.$sourceFile->id, $sourceHtml);
        $this->assertSame([$sourceFile->id], app(MarketingFileSourceService::class)->referencedFileIds($sourceHtml));

        $transfer = app(MarketingCreativeTransferService::class);
        $bundle = $transfer->export($creative);

        $this->assertSame(MarketingCreativeTransferService::FORMAT, $bundle['format']);
        $this->assertSame(MarketingCreativeTransferService::VERSION, $bundle['version']);
        $this->assertCount(1, $bundle['media']);
        $this->assertSame(hash('sha256', Storage::disk('private')->get($sourceFile->path)), $bundle['media'][0]['sha256']);
        $this->assertStringContainsString(
            'rtmedia://media-'.$bundle['media'][0]['sha256'],
            json_encode($bundle['creative'], JSON_UNESCAPED_SLASHES),
        );
        $this->assertStringNotContainsString(
            '/administrator/marketing/dateien/'.$sourceFile->id,
            json_encode($bundle['creative'], JSON_UNESCAPED_SLASHES),
        );

        $imported = $transfer->import($bundle, $admin);

        $this->assertNotSame($creative->id, $imported->id);
        $this->assertSame(MarketingCreativeStatus::Draft, $imported->status);
        $this->assertNull($imported->approved_at);
        $this->assertArrayNotHasKey('template_key', $imported->shared_content);
        $this->assertSame(
            $creative->shared_content['template_key'],
            $imported->shared_content['import_source_template_key'],
        );
        $this->assertCount(3, $imported->variants);
        $this->assertSame(2, File::query()->count());

        $importedFile = File::query()->whereKeyNot($sourceFile->id)->sole();
        Storage::disk('private')->assertExists($importedFile->path);
        $story = $imported->variants->firstWhere('format', MarketingCreativeFormat::Story);
        $this->assertStringContainsString(
            '/administrator/marketing/dateien/'.$importedFile->id,
            $story->html,
        );
        $this->assertStringNotContainsString('rtmedia://', $story->html);
        $this->assertSame(
            $story->content_hash,
            app(MarketingStudioService::class)->contentHash($story->builder_data, $story->html, $story->css),
        );
    }

    public function test_routes_are_admin_only_and_the_index_exposes_import_and_export_controls(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $staff = User::factory()->create(['role' => 'staff']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.index'))
            ->assertOk()
            ->assertSee('Motivpaket importieren')
            ->assertSee('Exportieren');

        $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.editor', $creative))
            ->assertOk()
            ->assertSee('JSON-Paket')
            ->assertSee('Vorteil 8');

        $exportResponse = $this->actingAs($admin)
            ->get(route('admin.marketing.creatives.export', $creative));
        $exportResponse
            ->assertOk()
            ->assertHeader('content-type', 'application/json; charset=UTF-8')
            ->assertHeader('x-content-type-options', 'nosniff')
            ->assertDownload();
        $this->assertStringNotContainsString("\n", $exportResponse->streamedContent());

        $this->actingAs($staff)
            ->get(route('admin.marketing.creatives.export', $creative))
            ->assertForbidden();

        $bundle = app(MarketingCreativeTransferService::class)->export($creative);
        $upload = UploadedFile::fake()->createWithContent(
            'motiv.json',
            json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->actingAs($staff)
            ->post(route('admin.marketing.creatives.import'), ['bundle' => $upload])
            ->assertForbidden();
    }

    public function test_import_route_creates_a_draft_and_rejects_a_tampered_media_hash(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $this->attachPrivateImage($creative);
        $bundle = app(MarketingCreativeTransferService::class)->export($creative);

        $validUpload = UploadedFile::fake()->createWithContent(
            'railtime-motiv.json',
            json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->actingAs($admin)
            ->post(route('admin.marketing.creatives.import'), ['bundle' => $validUpload])
            ->assertRedirect(route('admin.marketing.creatives.index'))
            ->assertSessionHas('marketing_import_success');
        $this->assertSame(2, MarketingCreative::query()->count());

        $bundle['media'][0]['sha256'] = str_repeat('0', 64);
        $tamperedUpload = UploadedFile::fake()->createWithContent(
            'railtime-motiv.json',
            json_encode($bundle, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        );
        $this->actingAs($admin)
            ->from(route('admin.marketing.creatives.index'))
            ->post(route('admin.marketing.creatives.import'), ['bundle' => $tamperedUpload])
            ->assertRedirect(route('admin.marketing.creatives.index'))
            ->assertSessionHasErrors('bundle');
        $this->assertSame(2, MarketingCreative::query()->count());
    }

    public function test_failed_import_rolls_back_database_and_removes_new_storage_blobs(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $sourceFile = $this->attachPrivateImage($creative);
        $bundle = app(MarketingCreativeTransferService::class)->export($creative);
        $bundle['creative']['variants']['story']['html'] = '<main><img src="rtmedia://'.$bundle['media'][0]['id'].'" alt=""></main>';

        try {
            app(MarketingCreativeTransferService::class)->import($bundle, $admin);
            $this->fail('Ein Import ohne offizielles Brand-Lockup muss fehlschlagen.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('html', $exception->errors());
        }

        $this->assertSame(1, MarketingCreative::query()->count());
        $this->assertSame(1, File::query()->count());
        $this->assertSame([$sourceFile->path], Storage::disk('private')->allFiles('uploads/files'));
    }

    public function test_import_rejects_unknown_transport_fields_instead_of_trusting_them(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Info,
            $admin,
        );
        $bundle = app(MarketingCreativeTransferService::class)->export($creative);
        $bundle['status'] = 'approved';

        try {
            app(MarketingCreativeTransferService::class)->import($bundle, $admin);
            $this->fail('Unbekannte Transportfelder müssen den Import blockieren.');
        } catch (ValidationException $exception) {
            $this->assertSame(
                ['Das Motivpaket enthält unbekannte Transportfelder.'],
                $exception->errors()['bundle'] ?? [],
            );
        }

        $this->assertSame(1, MarketingCreative::query()->count());
    }

    public function test_import_rejects_malformed_shared_content_before_it_can_break_the_editor(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $bundle = app(MarketingCreativeTransferService::class)->export($creative);
        $bundle['creative']['shared_content']['kicker'] = [];

        try {
            app(MarketingCreativeTransferService::class)->import($bundle, $admin);
            $this->fail('Ein nicht skalarer Kicker muss den Import blockieren.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('creative.shared_content.kicker', $exception->errors());
        }

        $this->assertSame(1, MarketingCreative::query()->count());
    }

    public function test_failed_blob_cleanup_is_logged_when_the_storage_driver_returns_false(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        $creative = app(MarketingStudioService::class)->createFromTemplate(
            MarketingCreativeType::Job,
            $admin,
        );
        $this->attachPrivateImage($creative);
        $bundle = app(MarketingCreativeTransferService::class)->export($creative);
        $bundle['creative']['variants']['story']['html'] = '<main><img src="rtmedia://'.$bundle['media'][0]['id'].'" alt=""></main>';

        $disk = Mockery::mock(Storage::disk('private'))->makePartial();
        $disk->shouldReceive('delete')->once()->andReturnFalse();
        Storage::shouldReceive('disk')->with('private')->andReturn($disk);
        Log::spy();

        try {
            app(MarketingCreativeTransferService::class)->import($bundle, $admin);
            $this->fail('Der ungültige Brand-Lockup muss den Import blockieren.');
        } catch (ValidationException) {
            $this->assertSame(1, MarketingCreative::query()->count());
        }

        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(static fn (string $message, array $context): bool => str_contains($message, 'Rollback')
                && str_starts_with((string) ($context['path'] ?? ''), 'uploads/files/marketing-import-'));
    }

    private function attachPrivateImage(MarketingCreative $creative): File
    {
        $contents = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNgYAAAAAMAASsJTYQAAAAASUVORK5CYII=',
            true,
        );
        $this->assertIsString($contents);
        $sha256 = hash('sha256', $contents);
        $path = 'uploads/files/portable-test.png';
        Storage::disk('private')->put($path, $contents);

        $file = FilePool::company()->files()->create([
            'folder_id' => null,
            'user_id' => $creative->created_by,
            'name' => 'portable-test.png',
            'path' => $path,
            'disk' => 'private',
            'mime_type' => 'image/png',
            'size' => strlen($contents),
            'content_sha256' => $sha256,
            'image_width' => 1,
            'image_height' => 1,
        ]);
        $url = route('admin.marketing.files.show', $file).'?v='.substr($sha256, 0, 16);

        $story = $creative->variants()->where('format', MarketingCreativeFormat::Story->value)->firstOrFail();
        $html = str_replace(
            '</main>',
            '<img class="portable-test" src="'.$url.'" alt="Portable Test"></main>',
            $story->html,
        );
        $builderData = app(MarketingContentBinder::class)->syncBuilderData($story->builder_data, $html);
        $story->forceFill([
            'html' => $html,
            'builder_data' => $builderData,
            'content_hash' => app(MarketingStudioService::class)->contentHash($builderData, $html, $story->css),
        ])->save();

        return $file;
    }
}
