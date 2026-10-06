<?php

namespace App\Services\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerPortalAttachment;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalRequest;
use App\Models\CustomerPortalSubmission;
use App\Models\User;
use App\Support\CustomerPortal\CustomerPortalIntakeSchema;
use App\Support\CustomerPortal\CustomerPortalScope;
use App\Support\CustomerPortal\CustomerPortalWorkflowSchema;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CustomerPortalIntakeAttachmentService
{
    public function upload(CustomerPortalIdentity $identity, int $customerId, string $type, int $id, string $uuid, UploadedFile $file): CustomerPortalAttachment
    {
        CustomerPortalWorkflowSchema::requireReady();
        Validator::make(compact('uuid', 'file'), ['uuid' => 'required|uuid', 'file' => 'required|file|mimes:pdf,jpg,jpeg,png|max:10240'])->validate();
        $this->source($identity, $customerId, $type, $id);
        $this->writable($customerId, $type, $id);
        $hash = hash_file('sha256', $file->getRealPath());
        $path = 'customer-portal/intake-quarantine/'.$customerId.'/'.Str::uuid().'/'.$hash;
        try {
            abort_unless(Storage::disk('local')->put($path, file_get_contents($file->getRealPath())), 503, 'Datei konnte nicht gespeichert werden.');

            return OperationsTransaction::run(function () use ($identity, $customerId, $type, $id, $uuid, $file, $hash, $path) {
                Customer::lockForUpdate()->findOrFail($customerId);
                $member = app(CustomerPortalScope::class)->membership($identity, $customerId, $type === 'submission' ? 'requests.create' : 'changes.create', true);
                $this->source($identity, $customerId, $type, $id);
                $this->writable($customerId, $type, $id);
                $existing = CustomerPortalAttachment::where('customer_id', $customerId)->where('client_uuid', $uuid)->first();
                if ($existing) {
                    abort_unless($existing->identity_id === $identity->id && $existing->source_type === $type && $existing->source_id === $id && hash_equals($existing->file_hash, $hash), 409);
                    Storage::disk('local')->delete($path);

                    return $existing;
                }
                $same = CustomerPortalAttachment::where('customer_id', $customerId)->where('identity_id', $identity->id)->where('source_type', $type)->where('source_id', $id)->where('file_hash', $hash)->first();
                if ($same) {
                    Storage::disk('local')->delete($path);

                    return $same;
                }
                abort_unless(CustomerPortalAttachment::where('customer_id', $customerId)->where('source_type', $type)->where('source_id', $id)->count() < 20, 422, 'Höchstens 20 Anlagen je Vorgang.');

                return CustomerPortalAttachment::create(['customer_id' => $customerId, 'identity_id' => $identity->id, 'membership_id' => $member->id, 'source_type' => $type, 'source_id' => $id,
                    'client_uuid' => $uuid, 'status' => 'quarantined', 'revision' => 1, 'file_path' => $path, 'file_name' => mb_substr(basename(str_replace('\\', '/', $file->getClientOriginalName())), 0, 180),
                    'file_mime' => $file->getMimeType(), 'file_size' => $file->getSize(), 'file_hash' => $hash]);
            }, 3);
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($path);
            throw $e;
        }
    }

    public function files(CustomerPortalIdentity $identity, int $customerId, string $type, int $id): array
    {
        CustomerPortalWorkflowSchema::requireReady();
        $this->source($identity, $customerId, $type, $id);

        return CustomerPortalAttachment::where('customer_id', $customerId)->where('identity_id', $identity->id)->where('source_type', $type)->where('source_id', $id)->get()->map(fn ($r) => ['id' => $r->id, 'name' => $r->file_name, 'status' => $r->status, 'revision' => $r->revision, 'downloadable' => $r->status === 'reviewed' && $r->reviewed_at !== null])->all();
    }

    public function review(User $actor, int $id, int $revision, bool $confirmedSafe, string $note): CustomerPortalAttachment
    {
        CustomerPortalWorkflowSchema::requireReady();
        Validator::make(compact('confirmedSafe', 'note'), ['confirmedSafe' => 'accepted', 'note' => 'required|string|min:5|max:2000'])->validate();
        $reference = CustomerPortalAttachment::findOrFail($id);
        app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');

        return OperationsTransaction::run(function () use ($actor, $reference, $id, $revision, $note) {
            Customer::lockForUpdate()->findOrFail($reference->customer_id);
            app(CustomerPortalScope::class)->authorizeManager($actor, $reference->customer_id, 'customers.portal.publish');
            $record = CustomerPortalAttachment::lockForUpdate()->findOrFail($id);
            abort_unless($record->revision === $revision && $record->status === 'quarantined', 409);
            $this->bytes($record);
            $record->forceFill(['status' => 'reviewed', 'revision' => $revision + 1, 'reviewed_by' => $actor->id, 'reviewed_at' => now()->utc(), 'review_note' => $note])->save();

            return $record;
        }, 3);
    }

    public function download(CustomerPortalIdentity $identity, int $customerId, int $id)
    {
        CustomerPortalWorkflowSchema::requireReady();
        $record = CustomerPortalAttachment::where('customer_id', $customerId)->where('identity_id', $identity->id)->findOrFail($id);
        $this->source($identity, $customerId, $record->source_type, $record->source_id);
        abort_unless($record->status === 'reviewed' && $record->reviewed_by && $record->reviewed_at, 409);

        return $this->response($record);
    }

    public function managerDownload(User $actor, int $id)
    {
        CustomerPortalWorkflowSchema::requireReady();
        $record = CustomerPortalAttachment::findOrFail($id);
        app(CustomerPortalScope::class)->authorizeManager($actor, $record->customer_id, 'customers.portal.publish');

        return $this->response($record);
    }

    /** Cleanup is limited to this service's opaque private paths without a persisted owner after rollback. */
    public function discardRolledBack(int $customerId, array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            abort_unless(is_string($path) && preg_match('#^customer-portal/intake-quarantine/'.preg_quote((string) $customerId, '#').'/[a-f0-9-]{36}/[a-f0-9]{64}$#D', $path), 422);
            if (! CustomerPortalAttachment::where('customer_id', $customerId)->where('file_path', $path)->exists()) {
                Storage::disk('local')->delete($path);
            }
        }
    }

    public function decisionIssues(int $customerId, string $type, int $id, array $manifest): array
    {
        CustomerPortalWorkflowSchema::requireReady();
        abort_unless(in_array($type, ['submission', 'request'], true), 422);
        $files = CustomerPortalAttachment::where('customer_id', $customerId)->where('source_type', $type)->where('source_id', $id)->get();
        $expected = [];
        foreach ($manifest as $item) {
            if (! is_array($item) || ! is_string($item['hash'] ?? null) || ! preg_match('/^[a-f0-9]{64}$/D', $item['hash']) || ! isset($item['size']) || (! is_int($item['size']) && ! (is_string($item['size']) && ctype_digit($item['size']))) || (int) $item['size'] < 0 || (int) $item['size'] > 10485760) {
                return ['attachments_manifest_invalid'];
            }
            if (isset($expected[$item['hash']]) && $expected[$item['hash']] !== (int) $item['size']) {
                return ['attachments_manifest_invalid'];
            }
            $expected[$item['hash']] = (int) $item['size'];
        }
        $actual = $files->pluck('file_hash')->unique()->sort()->values()->all();
        $expectedHashes = array_keys($expected);
        sort($expectedHashes);
        if ($actual !== $expectedHashes) {
            return ['attachments_manifest_changed'];
        }
        $issues = [];
        foreach ($files as $file) {
            if ($file->status !== 'reviewed' || ! $file->reviewed_at || ! $file->reviewed_by) {
                $issues[] = 'attachments_unreviewed';

                continue;
            }
            $reviewer = User::find($file->reviewed_by);
            if (! $reviewer?->status) {
                $issues[] = 'attachments_review_invalid';

                continue;
            }
            try {
                app(CustomerPortalScope::class)->authorizeManager($reviewer, $customerId, 'customers.portal.publish');
                $this->bytes($file);
            } catch (AuthorizationException|HttpException $e) {
                $issues[] = 'attachments_review_invalid';

                continue;
            }
            if ($file->file_size !== $expected[$file->file_hash]) {
                $issues[] = 'attachments_manifest_changed';
            }
        }

        return array_values(array_unique($issues));
    }

    private function writable(int $customerId, string $type, int $id): void
    {
        if ($type === 'submission') {
            abort_unless(CustomerPortalSubmission::where('customer_id', $customerId)->where('status', 'review')->whereKey($id)->exists(), 409, 'Anlagen benötigen einen offenen aktuellen Anfragevorgang.');
        } else {
            abort_unless(CustomerPortalRequest::where('customer_id', $customerId)->whereIn('status', ['submitted', 'reviewing'])->whereKey($id)->exists(), 409, 'Vorgang ist abgeschlossen.');
        }
    }

    private function response(CustomerPortalAttachment $record)
    {
        $this->bytes($record);

        return Storage::disk('local')->download($record->file_path, $record->file_name, ['Content-Type' => $record->file_mime, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff', 'Content-Security-Policy' => "default-src 'none'; sandbox"]);
    }

    private function bytes(CustomerPortalAttachment $record): void
    {
        abort_unless(str_starts_with($record->file_path, 'customer-portal/intake-quarantine/'.$record->customer_id.'/') && ! str_contains($record->file_path, '..') && Storage::disk('local')->exists($record->file_path), 404);
        abort_unless(hash_equals($record->file_hash, hash('sha256', Storage::disk('local')->get($record->file_path))), 409);
    }

    private function source(CustomerPortalIdentity $identity, int $customerId, string $type, int $id): void
    {
        abort_unless(in_array($type, ['submission', 'request'], true), 422);
        $member = app(CustomerPortalScope::class)->membership($identity, $customerId, $type === 'submission' ? 'requests.create' : 'changes.create');
        if ($type === 'submission') {
            CustomerPortalIntakeSchema::requireReady();
            $record = CustomerPortalSubmission::where('customer_id', $customerId)->where('identity_id', $identity->id)->findOrFail($id);
            if ($member->history_from) {
                abort_unless($record->created_at >= $member->history_from, 403);
            }
            if ($member->location_ids) {
                abort_unless($record->items()->exists() && ! $record->items()->where(fn ($q) => $q->whereNull('location_id')->orWhereNotIn('location_id', $member->location_ids))->exists(), 403);
            }
        } else {
            $record = CustomerPortalRequest::where('customer_id', $customerId)->where('identity_id', $identity->id)->findOrFail($id);
            if ($member->history_from) {
                abort_unless($record->created_at >= $member->history_from, 403);
            }
            if ($record->order_id) {
                app(CustomerPortalScope::class)->orders($identity, $customerId, null)->findOrFail($record->order_id);
            }
            if (($record->payload['target_type'] ?? '') === 'location' && $member->location_ids) {
                abort_unless(in_array((int) ($record->payload['target_id'] ?? 0), $member->location_ids, true), 403);
            }
        }
    }
}
