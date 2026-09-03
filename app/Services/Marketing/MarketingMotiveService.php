<?php

namespace App\Services\Marketing;

use App\Enums\MarketingCreativeStatus;
use App\Enums\MarketingCreativeType;
use App\Models\FilePool;
use App\Models\MarketingCreative;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use RuntimeException;
use Throwable;

final class MarketingMotiveService
{
    public const STORAGE_MODE = 'files';

    public const FILE_POOL_TYPE = 'marketing-motive';

    public const MAX_FILES = 20;

    public const MAX_FILE_KILOBYTES = 51_200;

    private const UPLOAD_DIRECTORY = 'uploads/marketing-motives';

    public function __construct(
        private readonly MarketingStudioService $studio,
    ) {}

    /**
     * @param  array<int, TemporaryUploadedFile>  $uploads
     */
    public function create(
        string $title,
        MarketingCreativeType $type,
        User $actor,
        array $uploads = [],
    ): MarketingCreative {
        $this->assertAdmin($actor);
        [$title, $type] = $this->validatedMetadata($title, $type);
        $uploads = $this->validatedUploads($uploads);
        $storedPaths = [];

        try {
            return DB::transaction(function () use ($title, $type, $actor, $uploads, &$storedPaths): MarketingCreative {
                $creative = MarketingCreative::query()->create([
                    'type' => $type,
                    'status' => MarketingCreativeStatus::Draft,
                    'title' => $title,
                    'shared_content' => $this->fileStorageContent(),
                    'approved_by' => null,
                    'approved_at' => null,
                    'approval_dependency_hash' => null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                ]);

                $pool = $creative->filePool()->create([
                    'title' => $title,
                    'type' => self::FILE_POOL_TYPE,
                    'description' => '',
                ]);

                foreach ($uploads as $upload) {
                    $path = $upload->store(self::UPLOAD_DIRECTORY, 'private');
                    if (! is_string($path) || $path === '') {
                        throw new RuntimeException('Eine Motivdatei konnte nicht gespeichert werden.');
                    }

                    $storedPaths[] = $path;
                    $mimeType = Storage::disk('private')->mimeType($path)
                        ?: $upload->getClientMimeType()
                        ?: 'application/octet-stream';

                    $pool->files()->create([
                        'folder_id' => null,
                        'user_id' => $actor->id,
                        'name' => $this->safeOriginalName($upload),
                        'path' => $path,
                        'disk' => 'private',
                        'mime_type' => $mimeType,
                        'type' => self::FILE_POOL_TYPE,
                        'size' => $upload->getSize(),
                        'expires_at' => null,
                        'visible_from' => null,
                        'auto_delete' => false,
                        'visible_teams' => null,
                    ]);
                }

                activity('marketing')
                    ->causedBy($actor)
                    ->performedOn($creative)
                    ->event('created')
                    ->withProperties([
                        'storage_mode' => self::STORAGE_MODE,
                        'file_pool_id' => $pool->id,
                        'file_count' => count($uploads),
                        'type' => $type->value,
                    ])
                    ->log('marketing_motive_created');

                return $creative->setRelation('filePool', $pool->load('files'));
            });
        } catch (Throwable $exception) {
            $this->deleteStoredPaths($storedPaths);

            throw $exception;
        }
    }

    public function update(
        MarketingCreative $creative,
        string $title,
        MarketingCreativeType $type,
        User $actor,
    ): MarketingCreative {
        $this->assertAdmin($actor);
        [$title, $type] = $this->validatedMetadata($title, $type);

        return DB::transaction(function () use ($creative, $title, $type, $actor): MarketingCreative {
            $locked = $this->lockedCreative($creative);
            $pool = $this->ensureFilePool($locked);
            $before = [
                'title' => (string) $locked->title,
                'type' => $locked->type->value,
            ];

            $locked->forceFill([
                'title' => $title,
                'type' => $type,
                'status' => MarketingCreativeStatus::Draft,
                'shared_content' => $this->fileStorageContent($locked->shared_content),
                'approved_by' => null,
                'approved_at' => null,
                'approval_dependency_hash' => null,
                'updated_by' => $actor->id,
            ]);

            if ($locked->isDirty()) {
                $locked->save();
            }

            $this->synchronizePool($pool, $title);

            if ($before !== ['title' => $title, 'type' => $type->value]) {
                activity('marketing')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->event('updated')
                    ->withProperties([
                        'before' => $before,
                        'after' => ['title' => $title, 'type' => $type->value],
                        'file_pool_id' => $pool->id,
                    ])
                    ->log('marketing_motive_updated');
            }

            return $locked->fresh()->setRelation('filePool', $pool->fresh());
        });
    }

    /**
     * Resolves the private pool for old records which predate file-backed
     * motives. Locking the creative row serializes the missing-pool check, so
     * concurrent first opens cannot create two pools.
     */
    public function filePoolFor(MarketingCreative $creative, User $actor): FilePool
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($creative, $actor): FilePool {
            $locked = $this->lockedCreative($creative);
            $poolExisted = $locked->filePool()->exists();
            $pool = $this->ensureFilePool($locked);

            $this->synchronizePool($pool, (string) $locked->title);

            if (! $poolExisted) {
                activity('marketing')
                    ->causedBy($actor)
                    ->performedOn($locked)
                    ->event('updated')
                    ->withProperties([
                        'storage_mode' => self::STORAGE_MODE,
                        'file_pool_id' => $pool->id,
                    ])
                    ->log('marketing_motive_file_pool_created');
            }

            return $pool->fresh();
        });
    }

    public function delete(MarketingCreative $creative, User $actor): void
    {
        $this->assertAdmin($actor);

        // Der bestehende Service invalidiert alte Render-Blobs und Varianten
        // samt Audit-Log. Das Model-Event laesst den neuen Motiv-Dateipool bei
        // diesem Soft-Delete bewusst unangetastet.
        $this->studio->delete($creative, $actor);
    }

    private function assertAdmin(User $actor): void
    {
        abort_unless($actor->isAdmin(), 403);
    }

    /** @return array{0:string,1:MarketingCreativeType} */
    private function validatedMetadata(string $title, MarketingCreativeType $type): array
    {
        $validated = Validator::make([
            'title' => Str::squish($title),
            'type' => $type->value,
        ], [
            'title' => ['required', 'string', 'max:160'],
            'type' => ['required', Rule::enum(MarketingCreativeType::class)],
        ])->validate();

        return [
            (string) $validated['title'],
            MarketingCreativeType::from((string) $validated['type']),
        ];
    }

    /**
     * @param  array<int, mixed>  $uploads
     * @return array<int, TemporaryUploadedFile>
     */
    private function validatedUploads(array $uploads): array
    {
        $validator = Validator::make(['uploads' => array_values($uploads)], [
            'uploads' => ['required', 'array', 'min:1', 'max:'.self::MAX_FILES],
            'uploads.*' => [
                'file',
                'max:'.self::MAX_FILE_KILOBYTES,
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (! $value instanceof TemporaryUploadedFile) {
                        $fail('Die Motivdatei muss zuerst sicher hochgeladen werden.');

                        return;
                    }

                    if (mb_strlen($value->getClientOriginalName()) > 255) {
                        $fail('Der Name der Motivdatei darf höchstens 255 Zeichen lang sein.');
                    }
                },
            ],
        ], [
            'uploads.required' => 'Ein neues Motiv benötigt mindestens eine Datei.',
            'uploads.min' => 'Ein neues Motiv benötigt mindestens eine Datei.',
            'uploads.max' => 'Pro Motiv können höchstens '.self::MAX_FILES.' Dateien gleichzeitig hochgeladen werden.',
            'uploads.*.file' => 'Eine ausgewählte Motivdatei ist nicht lesbar.',
            'uploads.*.max' => 'Jede Motivdatei darf höchstens 50 MB groß sein.',
        ]);

        if ($validator->fails()) {
            throw new ValidationException($validator);
        }

        /** @var array<int, TemporaryUploadedFile> $validated */
        $validated = $validator->validated()['uploads'] ?? [];

        return $validated;
    }

    private function lockedCreative(MarketingCreative $creative): MarketingCreative
    {
        return MarketingCreative::query()
            ->lockForUpdate()
            ->findOrFail($creative->getKey());
    }

    private function ensureFilePool(MarketingCreative $creative): FilePool
    {
        $pool = $creative->filePool()->lockForUpdate()->first();

        return $pool ?? $creative->filePool()->create([
            'title' => (string) $creative->title,
            'type' => self::FILE_POOL_TYPE,
            'description' => '',
        ]);
    }

    private function synchronizePool(FilePool $pool, string $title): void
    {
        $pool->forceFill([
            'title' => $title,
            'type' => self::FILE_POOL_TYPE,
        ]);

        if ($pool->isDirty()) {
            $pool->save();
        }
    }

    private function safeOriginalName(TemporaryUploadedFile $upload): string
    {
        $name = basename(str_replace('\\', '/', $upload->getClientOriginalName()));
        $name = trim(preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '');

        return Str::limit($name !== '' ? $name : 'motivdatei', 255, '');
    }

    /** @param array<string, mixed>|null $content */
    private function fileStorageContent(?array $content = null): array
    {
        $content ??= [];
        $content['storage_mode'] = self::STORAGE_MODE;

        return $content;
    }

    /** @param array<int, string> $paths */
    private function deleteStoredPaths(array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            try {
                $deleted = Storage::disk('private')->delete($path);

                if (! $deleted) {
                    Log::warning('Konnte unvollstaendig hochgeladene Motivdatei nicht entfernen.', [
                        'disk' => 'private',
                        'path' => $path,
                        'error' => 'Storage-Adapter meldete false.',
                    ]);
                }
            } catch (Throwable $cleanupException) {
                Log::warning('Konnte unvollstaendig hochgeladene Motivdatei nicht entfernen.', [
                    'disk' => 'private',
                    'path' => $path,
                    'error' => $cleanupException->getMessage(),
                ]);
            }
        }
    }
}
