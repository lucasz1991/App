<?php

namespace App\Services\Operations;

use App\Models\EmployeeDocumentRequirement;
use App\Models\EmployeeDocumentVersion;
use App\Models\File;
use App\Models\User;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentVersionService
{
    public static function ready(): bool
    {
        return Schema::hasTable('employee_document_versions') && Schema::hasTable('employee_document_requirements');
    }

    public function authorize(User $actor, int $userId, bool $edit = false): void
    {
        abort_unless($actor->status, 403);
        if (! $edit && $actor->id === $userId) {
            return;
        }
        app(PersonnelScopeService::class)->authorize($actor, $userId, $edit ? 'employees.master-data.edit' : 'employees.master-data.view');
    }

    public function save(int $userId, string $type, UploadedFile $upload, User $actor, ?int $expectedFileId = null): EmployeeDocumentVersion
    {
        $this->authorize($actor, $userId, true);
        $this->available($type);
        Validator::make(['upload' => $upload], ['upload' => ['required', 'file', 'max:12288', 'mimes:pdf,jpg,jpeg,png,webp,doc,docx']])->validate();
        $path = $upload->store('uploads/employee-documents/'.$userId.'/'.$type, 'private');
        try {
            return OperationsTransaction::run(function () use ($userId, $type, $upload, $path, $actor, $expectedFileId) {
                // The employee lock also serializes first creation of the requirement.
                User::lockForUpdate()->findOrFail($userId);
                $requirement = EmployeeDocumentRequirement::firstOrCreate(['user_id' => $userId, 'document_type' => $type]);
                $requirement = EmployeeDocumentRequirement::lockForUpdate()->findOrFail($requirement->id);
                if ($requirement->file?->id !== $expectedFileId) {
                    throw ValidationException::withMessages(['upload' => 'Die Unterlage wurde geändert. Bitte neu laden.']);
                }
                if ($previous = $requirement->file) {
                    $previousVersion = $this->archiveLegacy($requirement, $previous);
                    $previous->fileable()->associate($previousVersion);
                    $previous->save();
                }
                $file = $requirement->file()->create([
                    'user_id' => $actor->id, 'name' => $upload->getClientOriginalName(), 'path' => $path, 'disk' => 'private',
                    'mime_type' => Storage::disk('private')->mimeType($path), 'size' => $upload->getSize(), 'type' => 'employee-document',
                ]);
                $version = $this->newVersion($requirement, $file, $actor->id);
                $requirement->forceFill(['status' => 'uploaded', 'verified_at' => null, 'verified_by' => null])->save();
                $this->log($userId, $type, $actor, 'employee_document_uploaded', $version);

                return $version;
            });
        } catch (\Throwable $exception) {
            Storage::disk('private')->delete($path);
            throw $exception;
        }
    }

    /** Withdrawal removes only the current pointer, never the historical bytes. */
    public function withdraw(int $userId, string $type, User $actor, ?int $expectedFileId = null): void
    {
        $this->authorize($actor, $userId, true);
        $this->available($type);
        OperationsTransaction::run(function () use ($userId, $type, $actor, $expectedFileId): void {
            User::lockForUpdate()->findOrFail($userId);
            $requirement = EmployeeDocumentRequirement::where('user_id', $userId)->where('document_type', $type)->lockForUpdate()->firstOrFail();
            $file = $requirement->file;
            abort_unless($file, 404);
            if ($file->id !== $expectedFileId) {
                throw ValidationException::withMessages(['upload' => 'Die Unterlage wurde geändert. Bitte neu laden.']);
            }
            $version = $this->archiveLegacy($requirement, $file);
            $file->fileable()->associate($version);
            $file->save();
            $version->forceFill(['withdrawn_at' => now()->utc(), 'withdrawn_by' => $actor->id])->save();
            $requirement->forceFill(['status' => 'missing', 'verified_at' => null, 'verified_by' => null])->save();
            $this->log($userId, $type, $actor, 'employee_document_withdrawn', $version);
        });
    }

    public function download(int $userId, string $type, ?int $versionId, User $actor): StreamedResponse
    {
        $this->authorize($actor, $userId);
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);
        $requirement = EmployeeDocumentRequirement::where('user_id', $userId)->where('document_type', $type)->firstOrFail();
        $file = $versionId ? EmployeeDocumentVersion::where('employee_document_requirement_id', $requirement->id)->findOrFail($versionId)->file : $requirement->file;
        abort_unless($file && $file->disk === 'private' && Storage::disk('private')->exists($file->path), 404);

        return Storage::disk('private')->download($file->path, $file->name_with_extension, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function acknowledge(int $versionId, User $actor): void
    {
        abort_unless($actor->status, 403);
        abort_unless(self::ready(), 503);
        OperationsTransaction::run(function () use ($versionId, $actor): void {
            $reference = EmployeeDocumentVersion::findOrFail($versionId);
            $requirement = EmployeeDocumentRequirement::lockForUpdate()->findOrFail($reference->employee_document_requirement_id);
            abort_unless($actor->id === (int) $requirement->user_id, 403);
            $version = EmployeeDocumentVersion::lockForUpdate()->findOrFail($versionId);
            // A withdrawn version cannot be represented as the employee's current document.
            abort_unless(! $version->withdrawn_at && $requirement->file?->id === $version->file_id, 422);
            if ($version->acknowledged_at) {
                return;
            }
            $version->forceFill(['acknowledged_at' => now()->utc(), 'acknowledged_by' => $actor->id])->save();
            $this->log($requirement->user_id, $requirement->document_type, $actor, 'employee_document_acknowledged', $version);
        });
    }

    private function archiveLegacy(EmployeeDocumentRequirement $requirement, File $file): EmployeeDocumentVersion
    {
        return EmployeeDocumentVersion::where('file_id', $file->id)->first() ?? $this->newVersion($requirement, $file, $file->user_id);
    }

    private function newVersion(EmployeeDocumentRequirement $requirement, File $file, ?int $actorId): EmployeeDocumentVersion
    {
        return EmployeeDocumentVersion::create([
            'employee_document_requirement_id' => $requirement->id, 'file_id' => $file->id,
            'revision' => (int) EmployeeDocumentVersion::where('employee_document_requirement_id', $requirement->id)->max('revision') + 1,
            'snapshot' => $file->only(['name', 'mime_type', 'size']) + ['sha256' => hash_file('sha256', Storage::disk('private')->path($file->path))],
            'created_by' => $actorId, 'created_at' => $file->created_at ?? now()->utc(),
        ]);
    }

    private function available(string $type): void
    {
        abort_unless(self::ready(), 503, 'Dokumentversionen nicht verfügbar.');
        abort_unless(array_key_exists($type, EmployeeDocumentRequirement::TYPES), 404);
    }

    private function log(int $userId, string $type, User $actor, string $action, EmployeeDocumentVersion $version): void
    {
        activity('employee-master-data')->causedBy($actor)->performedOn(User::findOrFail($userId))
            ->withProperties(['target_user_id' => $userId, 'document_type' => $type, 'version_id' => $version->id, 'revision' => $version->revision])->log($action);
    }
}
