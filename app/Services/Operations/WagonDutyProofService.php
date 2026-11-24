<?php

namespace App\Services\Operations;

use App\Models\OperationWorkflow;
use App\Models\User;
use App\Support\Operations\OperationsTransaction;
use App\Support\WagonListWorkbookExporter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class WagonDutyProofService
{
    public function store(OperationWorkflow $record, int $revision, array $data, User $actor): void
    {
        $proofs = app(CustomerProofService::class);
        $proofs->authorizeWagon($record, $actor);
        $hasWagon = collect($data['wagons'])->contains(fn ($row) => filled($row['number58'] ?? null) || filled($row['category'] ?? null));
        if (! $hasWagon) {
            throw ValidationException::withMessages(['wagons' => 'Bitte mindestens einen Wagen mit Wagennummer oder Gattung erfassen.']);
        }

        $digest = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        $path = 'operations/customer-proofs/'.$id.'.xlsx';
        $temporary = app(WagonListWorkbookExporter::class)->export($data);
        $disk = Storage::disk('local');
        try {
            $bytes = file_get_contents($temporary);
            if ($bytes === false || ! $disk->put($path, $bytes)) {
                throw new \RuntimeException('Die Wagenliste konnte nicht gespeichert werden.');
            }
            $hash = hash('sha256', $bytes);
            $stored = OperationsTransaction::run(function () use ($record, $revision, $data, $actor, $proofs, $digest, $hash, $path, $id) {
                $current = OperationWorkflow::lockForUpdate()->findOrFail($record->id);
                $proofs->authorizeWagon($current, $actor);
                if (($current->payload['wagon_hash'] ?? null) === $digest) {
                    return false;
                }
                app(OperationsWorkflowService::class)->change($current->id, $revision, $actor, function ($row) use ($data, $digest, $hash, $path, $id) {
                    $payload = $row->payload;
                    $attachments = collect($payload['attachments'])->reject(fn ($file) => ($file['source'] ?? null) === 'wagon_list')->values();
                    app(OperationsWorkflowService::class)->check($attachments->count() < 20, 'Der Nachweis enthält bereits 20 Anlagen.');
                    $payload['attachments'] = $attachments->push(['id' => $id, 'name' => 'Wagenliste-'.$row->id.'.xlsx', 'source' => 'wagon_list', 'path' => $path, 'sha256' => $hash])->all();
                    $payload['wagon_hash'] = $digest;
                    $payload['wagon_data'] = $data;
                    $row->payload = $payload;

                    return 'wagon.attached';
                });

                return true;
            });
        } finally {
            unlink($temporary);
            if (! ($stored ?? false)) {
                $disk->delete($path);
            }
        }
    }
}
