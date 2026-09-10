<?php

namespace App\Services\Support;

use App\Jobs\ProcessMailJob;
use App\Models\Device;
use App\Models\DeviceAssignment;
use App\Models\DeviceDesktopClient;
use App\Models\Mail;
use App\Models\Setting;
use App\Models\SupportCase;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Support\SupportRecipient;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Validator;

final class SupportCaseService
{
    public const STATUSES = ['open' => 'Offen', 'in_progress' => 'In Bearbeitung', 'waiting_user' => 'Rückfrage', 'resolved' => 'Gelöst', 'closed' => 'Geschlossen'];

    public function create(User $user, array $input, ?DeviceDesktopClient $client = null): SupportCase
    {
        abort_unless($user->isActive() && $user->email_verified_at, 403);
        $data = Validator::make($input, [
            'request_id' => ['required', 'uuid'], 'subject' => ['required', 'string', 'min:5', 'max:160'],
            'message' => ['required', 'string', 'min:20', 'max:5000'],
            'category' => ['required', 'in:question,technical_issue,feedback,feature_request'],
            'device_id' => ['sometimes', 'nullable', 'uuid'], 'diagnostics' => ['sometimes', 'array'],
            'diagnostics_confirmed' => ['sometimes', 'boolean'],
        ])->validate();
        $diagnostics = [];
        if (! empty($data['diagnostics'])) {
            abort_unless(($data['diagnostics_confirmed'] ?? false) === true, 422, 'Diagnosevorschau zuerst bestätigen.');
            $diagnostics = SupportDiagnostics::validate($data['diagnostics']);
        }
        ksort($diagnostics);
        $device = $client ? Device::query()->findOrFail($client->device_id)
            : (empty($data['device_id']) ? null : Device::query()->where('public_id', $data['device_id'])->firstOrFail());
        if ($device) {
            abort_unless(DeviceAssignment::query()->where('device_id', $device->id)->where('user_id', $user->id)->active()->exists(), 403);
        }
        $subject = trim($data['subject']);
        $body = trim($data['message']);
        $hash = hash('sha256', json_encode([$subject, $body, $data['category'], $device?->id, $client?->id, $diagnostics], JSON_THROW_ON_ERROR));

        return DB::transaction(function () use ($user, $data, $subject, $body, $device, $client, $diagnostics, $hash): SupportCase {
            $user = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            abort_unless($user->isActive() && $user->email_verified_at, 403);
            $existing = SupportCase::query()->where('user_id', $user->id)->where('request_id', strtolower($data['request_id']))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'Anfrage-ID gehört bereits zu einem anderen Inhalt.');

                return $existing;
            }
            abort_if(RateLimiter::tooManyAttempts('support-case:'.$user->id, 3), 429, 'Bitte vor einer weiteren Anfrage kurz warten.');
            $case = SupportCase::query()->create(['user_id' => $user->id, 'device_id' => $device?->id,
                'client_id' => $client?->id, 'request_id' => strtolower($data['request_id']), 'request_hash' => $hash,
                'subject' => $subject, 'category' => $data['category'], 'status' => 'open',
                'diagnostics' => $diagnostics ?: null, 'diagnostics_expires_at' => $diagnostics ? now()->addDays(max(1, min(90, (int) (((array) Setting::getValueUncached('device_management', 'support'))['retention_days'] ?? 30)))) : null]);
            $case->messages()->create(['user_id' => $user->id, 'request_id' => strtolower($data['request_id']), 'body' => $body]);
            $this->notifySupport($case);
            $this->audit($case, $user, 'created');
            DB::afterCommit(fn () => RateLimiter::hit('support-case:'.$user->id, 600));

            return $case;
        }, 3);
    }

    public function authorize(SupportCase $case, User $actor): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->isActive() && $actor->email_verified_at, 403);
        abort_unless((int) $case->user_id === (int) $actor->id || Gate::forUser($actor)->allows('support.manage'), 403);
    }

    public function reply(SupportCase $case, User $actor, string $requestId, string $body): SupportCaseMessage
    {
        $this->authorize($case, $actor);
        Validator::make(['request_id' => $requestId, 'body' => $body], [
            'request_id' => ['required', 'uuid'], 'body' => ['required', 'string', 'min:2', 'max:5000'],
        ])->validate();

        return DB::transaction(function () use ($case, $actor, $requestId, $body): SupportCaseMessage {
            $case = SupportCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $this->authorize($case, $actor);
            $existing = $case->messages()->where('user_id', $actor->id)->where('request_id', strtolower($requestId))->first();
            if ($existing) {
                abort_unless(hash_equals($existing->body, trim($body)), 409);

                return $existing;
            }
            $fromSupport = (int) $case->user_id !== (int) $actor->id;
            $message = $case->messages()->create(['user_id' => $actor->id, 'request_id' => strtolower($requestId), 'body' => trim($body), 'from_support' => $fromSupport]);
            $case->update(['status' => $fromSupport ? 'waiting_user' : 'open', 'closed_at' => null]);
            $this->audit($case, $actor, 'replied');

            return $message;
        }, 3);
    }

    public function transition(SupportCase $case, User $actor, string $status): void
    {
        abort_unless(isset(self::STATUSES[$status]), 422);
        DB::transaction(function () use ($case, $actor, $status): void {
            $case = SupportCase::query()->whereKey($case->id)->lockForUpdate()->firstOrFail();
            $actor = $actor->fresh();
            $this->authorize($case, $actor);
            if ((int) $case->user_id === (int) $actor->id && ! Gate::forUser($actor)->allows('support.manage')) {
                abort_unless(in_array($status, ['open', 'resolved', 'closed'], true), 403);
            }
            $case->update(['status' => $status, 'closed_at' => $status === 'closed' ? now() : null]);
            $this->audit($case, $actor, 'status-'.$status);
        }, 3);
    }

    public function serialize(SupportCase $case, User $actor): array
    {
        $this->authorize($case, $actor);

        return ['id' => $case->public_id, 'subject' => $case->subject, 'status' => $case->status,
            'status_label' => self::STATUSES[$case->status] ?? $case->status,
            'created_at' => $case->created_at->toIso8601String(),
            'attachments' => $case->attachments()->where('expires_at', '>', now())->get()->map(fn ($file) => ['name' => $file->name, 'url' => route('support.attachment', $file->public_id), 'expires_at' => $file->expires_at->toIso8601String()])->all(),
            'diagnostics' => $case->diagnostics_expires_at?->isFuture() ? $case->diagnostics : null,
            'messages' => $case->messages()->latest('id')->limit(200)->get()->reverse()->values()->map(fn ($m) => [
                'body' => $m->body, 'from_support' => $m->from_support, 'created_at' => $m->created_at->toIso8601String(),
            ])->all()];
    }

    private function notifySupport(SupportCase $case): void
    {
        $recipient = SupportRecipient::resolve();
        if (! $recipient) {
            return; // The durable case remains visible even if mail is not configured.
        }
        $mail = Mail::withoutEvents(fn () => Mail::query()->create(['type' => 'mail', 'status' => false,
            'content' => ['subject' => '[IT-Support] Neue Anfrage '.$case->public_id, 'header' => 'Neue Supportanfrage',
                'body' => 'Eine neue Anfrage liegt im geschützten RailTime-Supportbereich bereit.', 'lines' => [],
                'link' => url('/support/faelle?fall='.$case->public_id), 'support_request' => true],
            'recipients' => [['email' => $recipient, 'status' => false]]]));
        // Do not let synchronous mail processing run before the durable case commits.
        ProcessMailJob::dispatch($mail)->afterCommit();
    }

    private function audit(SupportCase $case, User $actor, string $event): void
    {
        activity('device-support')->performedOn($case)->causedBy($actor)->event('support.'.$event)
            ->withProperties(['case_id' => $case->public_id])->log('Support '.$event);
    }
}
