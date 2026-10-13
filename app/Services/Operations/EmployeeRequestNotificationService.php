<?php

namespace App\Services\Operations;

use App\Enums\ShiftAssignmentStatus;
use App\Jobs\DeliverEmployeeRequestNotification;
use App\Jobs\ProcessMailJob;
use App\Models\Customer;
use App\Models\CustomerCapacityCommitment;
use App\Models\CustomerLocation;
use App\Models\EmployeeRequestNotification;
use App\Models\Mail;
use App\Models\Message;
use App\Models\OperationAudit;
use App\Models\ShiftAssignment;
use App\Models\User;
use App\Notifications\EmployeeRequestWebPushNotification;
use App\Support\Operations\OperationsAccess;
use App\Support\Push\PushDelivery;
use Closure;
use Illuminate\Bus\UniqueLock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use NotificationChannels\WebPush\WebPushChannel;
use RuntimeException;
use Throwable;

/** Only explicit request transitions create receipts. No historical-request scan. */
final class EmployeeRequestNotificationService
{
    public function shiftRequested(ShiftAssignment $assignment, User $actor, bool $changedPlan = false): void
    {
        if (! $this->ready()) {
            return;
        }
        $assignment->loadMissing('shift');
        if ($assignment->status !== ShiftAssignmentStatus::Requested
            || $assignment->plan_revision < 1
            || $assignment->shift->published_revision !== $assignment->plan_revision
            || $assignment->shift->revision !== $assignment->plan_revision) {
            return;
        }
        // Re-requesting a declined assignment is a new lifecycle even when the
        // published plan revision is unchanged. Repeated requested saves are
        // excluded by the assignment service before reaching this boundary.
        $generation = OperationAudit::where('subject_type', 'ShiftAssignment')->where('subject_id', $assignment->id)
            ->where('action', 'assignment.saved')->max('id') ?? 0;
        $this->stage('shift:'.$assignment->id.':'.$assignment->plan_revision.':'.$generation, [
            'request_type' => 'shift', 'request_id' => $assignment->id, 'request_revision' => $assignment->plan_revision,
            'user_id' => $assignment->user_id, 'requested_by' => $actor->id,
            'kind' => $changedPlan ? 'shift_changed' : 'shift_requested',
        ]);
    }

    public function capacityRequested(CustomerCapacityCommitment $commitment, User $actor): void
    {
        if (! $this->ready() || $commitment->status !== 'requested') {
            return;
        }
        $this->stage('capacity:'.$commitment->id.':'.$commitment->revision, [
            'request_type' => 'capacity', 'request_id' => $commitment->id, 'request_revision' => $commitment->revision,
            'user_id' => $commitment->user_id, 'requested_by' => $actor->id, 'kind' => 'capacity_requested',
        ]);
    }

    private function stage(string $eventKey, array $attributes): void
    {
        $receipt = EmployeeRequestNotification::firstOrCreate(['event_key' => $eventKey], $attributes);
        if ($receipt->wasRecentlyCreated) {
            $id = $receipt->id;
            DB::afterCommit(function () use ($id): void {
                try {
                    $this->dispatchReceipt($id);
                } catch (Throwable $exception) {
                    // The committed receipt remains retryable via the dedicated
                    // dispatcher, without recreating business requests.
                    $this->failed($id, $exception);
                    Log::warning('Mitarbeiteranfrage konnte nicht in die Queue eingestellt werden.', [
                        'receipt_id' => $id, 'error_class' => $exception::class,
                    ]);
                }
            });
        }
    }

    public function ready(): bool
    {
        return Schema::hasTable('employee_request_notifications') && Schema::hasColumns('employee_request_notifications', [
            'event_key', 'request_type', 'request_id', 'request_revision', 'user_id', 'requested_by',
            'message_id', 'mail_id', 'push_claim_token', 'push_claimed_at', 'mail_failure_version',
        ]);
    }

    public function dispatchReceipt(int $receiptId): void
    {
        $job = new DeliverEmployeeRequestNotification($receiptId);
        try {
            DeliverEmployeeRequestNotification::dispatch($receiptId);
        } catch (Throwable $exception) {
            // PendingDispatch acquired the unique lock before touching the queue.
            // An enqueue outage must not block the receipt's scheduled recovery.
            try {
                (new UniqueLock(app('cache')->store()))->release($job);
            } catch (Throwable) {
                // Preserve the original queue failure for diagnostics and retry.
            }
            $this->failed($receiptId, $exception);
            throw $exception;
        }
    }

    public function mayNotify(int $receiptId, int $userId): bool
    {
        if (! $this->ready()) {
            return false;
        }
        $receipt = EmployeeRequestNotification::find($receiptId);

        return $receipt && $receipt->user_id === $userId && ! $receipt->skipped_at && $this->source($receipt) !== null;
    }

    /** An outbox job always executes after the business transaction commits. */
    public function deliver(int $receiptId): void
    {
        if (DB::transactionLevel() > 0) {
            throw new LogicException('Employee request delivery must run after commit.');
        }
        if (! $this->ready()) {
            return;
        }
        try {
            DB::transaction(function () use ($receiptId): void {
                $receipt = EmployeeRequestNotification::lockForUpdate()->find($receiptId);
                if (! $receipt || $receipt->enqueued_at || $receipt->skipped_at) {
                    return;
                }
                $receipt->increment('attempts');
                $source = $this->source($receipt);
                if (! $source) {
                    $receipt->update(['skipped_at' => now()->utc(), 'skip_reason' => 'request_no_longer_actionable']);

                    return;
                }
                $recipient = User::findOrFail($receipt->user_id);
                $content = $this->content($receipt, $source);
                $url = $this->url($receipt);
                if (! $receipt->message_id) {
                    $message = $recipient->receiveMessage($content['subject'], $content['body'], $receipt->requested_by, null, $url, 'Anfrage ansehen & antworten', afterCommit: true);
                    $receipt->forceFill(['message_id' => $message->id, 'message_created_at' => now()->utc()])->save();
                }
                if (! $receipt->mail_id && ! $receipt->mail_skipped_at) {
                    if (filter_var($recipient->email, FILTER_VALIDATE_EMAIL)) {
                        // Prepare the native Mail atomically with its receipt. Its
                        // ordinary observer is replaced with one explicit dispatch
                        // below, after this transaction commits.
                        $mail = Mail::withoutEvents(fn () => Mail::create([
                            'type' => 'mail', 'status' => false,
                            'content' => $content + [
                                'header' => 'Guten Tag '.$recipient->name.'.', 'link' => $url,
                                'employee_request_receipt_id' => $receipt->id,
                            ],
                            'recipients' => [['user_id' => $recipient->id, 'status' => false]],
                        ]));
                        $receipt->forceFill(['mail_id' => $mail->id])->save();
                    } else {
                        $receipt->update(['mail_skipped_at' => now()->utc()]);
                    }
                }
            }, 3);

            $receipt = EmployeeRequestNotification::find($receiptId);
            if (! $receipt || $receipt->enqueued_at || $receipt->skipped_at) {
                return;
            }
            // The receiveMessage after-commit callback handles the normal first
            // push. A retry only revisits an explicitly unfinished channel.
            if (! $receipt->push_enqueued_at && ! $receipt->push_skipped_at && $receipt->message_id) {
                app(PushDelivery::class)->messageReceived(Message::findOrFail($receipt->message_id));
            }
            $receipt->refresh();
            if ($receipt->mail_id && ! $receipt->mail_enqueued_at && ! $receipt->mail_skipped_at) {
                if ($this->source($receipt)) {
                    $failureVersion = $receipt->mail_failure_version;
                    ProcessMailJob::dispatch(Mail::findOrFail($receipt->mail_id));
                    // A fast child can fail before dispatch returns. Only this
                    // unchanged attempt may mark the native job enqueued.
                    EmployeeRequestNotification::whereKey($receipt->id)->where('mail_failure_version', $failureVersion)
                        ->whereNull('mail_skipped_at')->update(['mail_enqueued_at' => now()->utc()]);
                } else {
                    $receipt->update(['mail_skipped_at' => now()->utc()]);
                }
            }
            $receipt->refresh();
            if ($receipt->message_id && ($receipt->mail_enqueued_at || $receipt->mail_skipped_at)
                && ($receipt->push_enqueued_at || $receipt->push_skipped_at)) {
                // A child can also fail after this final refresh. Completion
                // must never overwrite the failure's reopened recovery state.
                EmployeeRequestNotification::whereKey($receipt->id)->where('mail_failure_version', $receipt->mail_failure_version)
                    ->whereNull('enqueued_at')->whereNull('skipped_at')
                    ->update(['enqueued_at' => now()->utc(), 'last_error_class' => null]);
            }
        } catch (Throwable $exception) {
            $this->failed($receiptId, $exception);
            throw $exception;
        }
    }

    /** Return false for ordinary messages: their existing push contract stays unchanged. */
    public function queueMessagePush(Message $message): bool
    {
        $target = $this->approvedTarget($message->action_url);
        if (! $target || ! $this->ready()) {
            return false;
        }
        $receiptId = EmployeeRequestNotification::where('message_id', $message->id)->value('id');
        if (! $receiptId) {
            return false;
        }
        if (DB::transactionLevel() > 0) {
            DB::afterCommit(fn () => $this->queueMessagePush($message));

            return true;
        }
        $claim = DB::transaction(function () use ($receiptId, $message, $target): ?array {
            $receipt = EmployeeRequestNotification::lockForUpdate()->find($receiptId);
            if (! $receipt || $receipt->push_enqueued_at || $receipt->push_skipped_at || $receipt->skipped_at
                || ($receipt->push_claim_token && $receipt->push_claimed_at?->gt(now()->subMinutes(5)))) {
                return null;
            }
            $recipient = User::find($receipt->user_id);
            if (! $recipient || (int) $message->to_user !== $recipient->id
                || $target !== [$receipt->request_type, $receipt->request_id, $receipt->request_revision]
                || ! $this->source($receipt)) {
                $receipt->update(['push_skipped_at' => now()->utc(), 'push_claim_token' => null, 'push_claimed_at' => null]);

                return null;
            }
            $notification = new EmployeeRequestWebPushNotification($receipt->id, 'message:'.$message->id, $message->subject,
                Str::of($message->message)->stripTags()->squish()->limit(160)->toString(), $message->action_url);
            if (! $notification->shouldSend($recipient, WebPushChannel::class)) {
                $receipt->update(['push_skipped_at' => now()->utc(), 'push_claim_token' => null, 'push_claimed_at' => null]);

                return null;
            }
            $token = (string) Str::uuid();
            $receipt->update(['push_claim_token' => $token, 'push_claimed_at' => now()->utc()]);

            return ['token' => $token, 'recipient' => $recipient, 'notification' => $notification];
        }, 3);
        if (! $claim) {
            return true;
        }
        try {
            // No transaction surrounds the queue boundary: acceptance is known
            // before the receipt marks it enqueued. A concurrent worker sees the
            // committed lease instead of scheduling a second native notification.
            $claim['recipient']->notify($claim['notification']);
            EmployeeRequestNotification::whereKey($receiptId)->where('push_claim_token', $claim['token'])->update([
                'push_enqueued_at' => now()->utc(), 'push_claim_token' => null, 'push_claimed_at' => null,
            ]);
        } catch (Throwable $exception) {
            EmployeeRequestNotification::whereKey($receiptId)->where('push_claim_token', $claim['token'])->update([
                'push_claim_token' => null, 'push_claimed_at' => null, 'last_error_class' => Str::limit($exception::class, 190, ''),
            ]);
            throw $exception;
        }

        return true;
    }

    public function mayMail(Mail $mail, object $notifiable): bool
    {
        $id = (int) ($mail->content['employee_request_receipt_id'] ?? 0);
        if (! $id) {
            return true;
        }
        if (! $notifiable instanceof User || ! $this->ready()) {
            return false;
        }
        $receipt = EmployeeRequestNotification::find($id);

        return $receipt && $receipt->mail_id === $mail->id && $receipt->user_id === $notifiable->id
            && filter_var($notifiable->fresh()?->email, FILTER_VALIDATE_EMAIL) !== false
            && $this->source($receipt) !== null;
    }

    /** Opt-in native mail-job dedupe; ordinary Mail processing is unchanged. */
    public function processMail(Mail $mail, Closure $send): void
    {
        try {
            DB::transaction(function () use ($mail, $send): void {
                $locked = Mail::lockForUpdate()->findOrFail($mail->id);
                $receipt = EmployeeRequestNotification::find((int) ($locked->content['employee_request_receipt_id'] ?? 0));
                if (! $receipt || $receipt->mail_id !== $locked->id || $locked->status) {
                    return;
                }
                $recipient = User::find($receipt->user_id);
                $recipients = $locked->recipients ?? [];
                if (! $recipient || ! $this->mayMail($locked, $recipient)
                    || count($recipients) !== 1 || (int) ($recipients[0]['user_id'] ?? 0) !== $recipient->id
                    || isset($recipients[0]['email'])) {
                    $receipt->update(['mail_skipped_at' => now()->utc()]);

                    return;
                }
                $send($locked);
                if (! $locked->fresh()->status) {
                    throw new RuntimeException('Employee request mail could not be enqueued.');
                }
            }, 3);
        } catch (Throwable $exception) {
            // A queue exception after the transaction committed leaves a durable
            // channel to retry, not an incorrectly completed native Mail marker.
            $mail->refresh()->update(['status' => false]);
            $receiptId = (int) ($mail->content['employee_request_receipt_id'] ?? 0);
            EmployeeRequestNotification::whereKey($receiptId)->where('mail_id', $mail->id)->update([
                'mail_enqueued_at' => null, 'enqueued_at' => null, 'mail_failure_version' => DB::raw('mail_failure_version + 1'),
            ]);
            $this->failed($receiptId, $exception);
            throw $exception;
        }
    }

    private function source(EmployeeRequestNotification $receipt): ShiftAssignment|CustomerCapacityCommitment|null
    {
        $recipient = User::find($receipt->user_id);
        if (! OperationsAccess::isEmployee($recipient)) {
            return null;
        }
        if (EmployeeRequestNotification::where('request_type', $receipt->request_type)->where('request_id', $receipt->request_id)
            ->where('id', '>', $receipt->id)->exists()) {
            return null;
        }
        if ($receipt->request_type === 'shift') {
            $assignment = ShiftAssignment::with('shift')->find($receipt->request_id);
            $shift = $assignment?->shift;
            if (! $shift || $assignment->user_id !== $receipt->user_id || $assignment->status !== ShiftAssignmentStatus::Requested
                || $assignment->plan_revision !== $receipt->request_revision || $shift->revision !== $receipt->request_revision
                || $shift->published_revision !== $receipt->request_revision || ! $shift->published_at
                || in_array($shift->status->value, ['cancelled', 'completed'], true) || ! $shift->ends_at->isFuture()) {
                return null;
            }

            return $assignment;
        }
        if ($receipt->request_type === 'capacity' && Schema::hasTable('customer_capacity_commitments')) {
            $commitment = CustomerCapacityCommitment::find($receipt->request_id);
            if ($commitment && $commitment->user_id === $receipt->user_id && $commitment->revision === $receipt->request_revision
                && $commitment->status === 'requested' && $commitment->starts_at->isFuture()) {
                return $commitment;
            }
        }

        return null;
    }

    private function content(EmployeeRequestNotification $receipt, ShiftAssignment|CustomerCapacityCommitment $source): array
    {
        $shift = $source instanceof ShiftAssignment ? $source->shift : null;
        $timezone = $shift?->timezone ?? $source->timezone;
        $from = $shift?->starts_at ?? $source->starts_at;
        $until = $shift?->ends_at ?? $source->ends_at;
        $subject = match ($receipt->kind) {
            'shift_changed' => 'Geänderter Dienst · Ihre Zustimmung ist erforderlich',
            'capacity_requested' => 'Verfügbarkeitsanfrage · Bitte antworten',
            default => 'Neue Dienstanfrage · Bitte antworten',
        };
        $title = $shift?->title ?? $source->role_name;
        $location = $shift?->location_name ?? CustomerLocation::find($source->location_id)?->name;
        $customer = $shift ? null : Customer::find($source->customer_id)?->company_name;
        $lines = array_filter([
            $receipt->kind === 'shift_changed' ? 'Ein veröffentlichter Dienst wurde geändert. Bitte prüfen Sie die neue Fassung und antworten Sie in Mein Arbeitstag.'
                : ($shift ? 'Für Sie liegt eine neue Dienstanfrage vor. Bitte prüfen Sie den Dienst und antworten Sie in Mein Arbeitstag.'
                    : 'Für Sie liegt eine Verfügbarkeitsanfrage vor. Bitte prüfen Sie den genauen Zeitraum und antworten Sie in Mein Arbeitstag.'),
            trim($title.($customer ? ' · '.$customer : '')),
            $from->copy()->setTimezone($timezone)->format('d.m.Y H:i').' – '.$until->copy()->setTimezone($timezone)->format('d.m.Y H:i').' · '.$timezone,
            $location ? 'Einsatzort: '.$location : null,
            'Der Link öffnet die Anfrage. Ihre Zustimmung oder Ablehnung erfolgt ausschließlich nach Ihrer persönlichen Bestätigung.',
        ]);

        return ['subject' => $subject, 'body' => implode("\n", $lines), 'lines' => array_values($lines)];
    }

    public function url(EmployeeRequestNotification $receipt): string
    {
        return route('operations.mine', ['tab' => 'today', 'request_type' => $receipt->request_type,
            'request_id' => $receipt->request_id, 'request_revision' => $receipt->request_revision]);
    }

    /** Exact MyWork contract, same application origin, no fragments or extra query actions. */
    private function approvedTarget(?string $url): ?array
    {
        if (! $url) {
            return null;
        }
        $parts = parse_url($url);
        $app = parse_url(route('operations.mine'));
        if (! is_array($parts) || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || ($parts['path'] ?? '') !== ($app['path'] ?? '')) {
            return null;
        }
        if (isset($parts['host']) && (($parts['scheme'] ?? '') !== ($app['scheme'] ?? '')
            || $parts['host'] !== ($app['host'] ?? '') || ($parts['port'] ?? null) !== ($app['port'] ?? null))) {
            return null;
        }
        if ((! isset($parts['host']) && ! str_starts_with($url, '/')) || str_starts_with($url, '//')) {
            return null;
        }
        $queryText = $parts['query'] ?? '';
        if (count(explode('&', $queryText)) !== 4) {
            return null;
        }
        parse_str($queryText, $query);
        $keys = array_keys($query);
        sort($keys);
        if ($keys !== ['request_id', 'request_revision', 'request_type', 'tab'] || ($query['tab'] ?? null) !== 'today'
            || ! in_array($query['request_type'] ?? null, ['shift', 'capacity'], true)
            || ! is_string($query['request_id']) || ! ctype_digit($query['request_id']) || (int) $query['request_id'] < 1
            || ! is_string($query['request_revision']) || ! ctype_digit($query['request_revision']) || (int) $query['request_revision'] < 1) {
            return null;
        }

        return [$query['request_type'], (int) $query['request_id'], (int) $query['request_revision']];
    }

    private function failed(int $receiptId, Throwable $exception): void
    {
        EmployeeRequestNotification::whereKey($receiptId)->update(['last_error_class' => Str::limit($exception::class, 190, '')]);
    }
}
