<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\DispositionMailboxReview;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsTransaction;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class DispositionMailboxService
{
    public function __construct(private readonly AiIntakeImapClient $imap) {}

    private function authorize(User $actor): User
    {
        $fresh = User::findOrFail($actor->id);
        OperationsAccess::authorize($fresh, 'operations.inquiries.manage');
        OperationsAccess::requireReady();

        return $fresh;
    }

    public function available(User $actor): bool
    {
        $fresh = User::find($actor->id);
        $settings = AiDispositionSettings::all(true);

        return $fresh?->isActive() && $fresh->can('operations.inquiries.manage') && OperationsAccess::ready()
            && $settings['imap_host'] !== '' && $settings['imap_username'] !== '' && $settings['imap_password'] !== '';
    }

    private function settings(User $actor): array
    {
        $this->authorize($actor);
        abort_unless($this->available($actor), 409, 'Dispositionspostfach ist nicht verbunden.');

        return AiDispositionSettings::all(true);
    }

    private function unchanged(User $actor, array $settings): void
    {
        $fresh = $this->settings($actor);
        abort_unless($fresh['revision'] === $settings['revision'] && AiDispositionSettings::mailboxId($fresh) === AiDispositionSettings::mailboxId($settings), 409, 'Postfach wurde geändert. Bitte neu öffnen.');
    }

    public function connection(User $actor): array
    {
        $settings = $this->settings($actor);
        $state = ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'settings_revision' => (int) $settings['revision'], 'address' => $settings['imap_username']];
        try {
            $snapshot = $this->imap->snapshot($settings);
            $this->unchanged($actor, $settings);
            abort_unless($snapshot['uid_validity'] > 0, 503);

            return $state + ['connected' => true];
        } catch (\Throwable $error) {
            if ($error instanceof HttpExceptionInterface && in_array($error->getStatusCode(), [403, 409], true)) {
                throw $error;
            }

            return $state + ['connected' => false, 'error' => 'Postfach derzeit nicht erreichbar. Verbindung prüfen und erneut versuchen.'];
        }
    }

    public function inbox(User $actor, int $page = 1, string $search = ''): array
    {
        $settings = $this->settings($actor);
        $result = $this->imap->browse($settings, $settings['imap_folder'], max(1, $page), 20, $this->search($search));
        $this->unchanged($actor, $settings);

        return $this->decorate($result, $settings) + ['available' => true];
    }

    public function sent(User $actor, int $page = 1, string $search = ''): array
    {
        $settings = $this->settings($actor);
        $folder = $this->imap->sentFolder($settings);
        $this->unchanged($actor, $settings);
        if (! $folder) {
            return ['available' => false, 'folder' => '', 'uid_validity' => 0, 'total' => 0, 'page' => 1, 'per_page' => 20, 'messages' => []];
        }
        $result = $this->imap->browse($settings, $folder, max(1, $page), 20, $this->search($search));
        $this->unchanged($actor, $settings);

        return $result + ['available' => true];
    }

    public function detail(User $actor, string $folder, int $uid, int $validity): array
    {
        $settings = $this->settings($actor);
        $this->folder($settings, $folder);
        $this->identity($uid, $validity);
        $result = $this->imap->readMessage($settings, $folder, $uid, $validity);
        $this->unchanged($actor, $settings);

        return $result + ($folder === $settings['imap_folder'] ? $this->classification($settings, $folder, $uid, $validity) : []);
    }

    public function classify(User $actor, string $folder, int $uid, int $validity, string $classification, ?int $expectedReviewRevision = null, ?int $expectedIntakeRevision = null): array
    {
        $settings = $this->settings($actor);
        abort_unless($folder === $settings['imap_folder'] && isset(DispositionMailboxReview::LABELS[$classification]), 422);
        $this->requireReviews();
        $this->identity($uid, $validity);
        // Check existence and UIDVALIDITY on the server before persisting a classification.
        $this->imap->readMessage($settings, $folder, $uid, $validity);
        $this->unchanged($actor, $settings);
        OperationsTransaction::run(function () use ($actor, $settings, $folder, $uid, $validity, $classification, $expectedReviewRevision, $expectedIntakeRevision): void {
            $this->unchanged($actor, $settings);
            $review = DispositionMailboxReview::firstOrCreate($this->reviewIdentity($settings, $folder, $uid, $validity));
            $review = DispositionMailboxReview::lockForUpdate()->findOrFail($review->id);
            abort_if($expectedReviewRevision !== null && $review->revision !== $expectedReviewRevision, 409, 'Die Mail wurde inzwischen geprüft. Bitte den aktuellen Stand laden.');
            $intake = $this->source($settings, $uid, $validity)?->intake;
            if ($expectedIntakeRevision !== null) {
                $intake = $intake ? AiIntake::lockForUpdate()->findOrFail($intake->id) : null;
                abort_unless($intake && $intake->revision === $expectedIntakeRevision, 409, 'Der Eingang wurde inzwischen geändert. Bitte neu prüfen.');
            }
            if ($classification === 'not_inquiry' && $intake && ! in_array($intake->status, ['paused', 'completed'], true)) {
                app(AiIntakeService::class)->pause($intake, $actor, $intake->revision);
            }
            $previous = $review->classification;
            $review->update(['classification' => $classification, 'intake_id' => $intake?->id, 'reviewed_by' => $actor->id, 'revision' => $review->revision + 1]);
            app(OperationsAuditService::class)->record($review, $actor, 'mailbox.classify', ['from' => $previous, 'to' => $classification]);
        });

        return $this->classification($settings, $folder, $uid, $validity);
    }

    public function importInquiry(User $actor, string $folder, int $uid, int $validity): AiIntake
    {
        return $this->capture($actor, $folder, $uid, $validity, true);
    }

    /** Capture originals for an explicit recognition run without deciding their intent. */
    public function captureForRecognition(User $actor, string $folder, int $uid, int $validity, int $expectedSettingsRevision): AiIntake
    {
        return $this->capture($actor, $folder, $uid, $validity, false, $expectedSettingsRevision);
    }

    private function capture(User $actor, string $folder, int $uid, int $validity, bool $asInquiry, ?int $expectedSettingsRevision = null): AiIntake
    {
        $settings = $this->settings($actor);
        abort_if($expectedSettingsRevision !== null && (int) $settings['revision'] !== $expectedSettingsRevision, 409, 'Das Postfach wurde geändert. Bitte neu öffnen.');
        abort_unless($folder === $settings['imap_folder'], 422);
        $this->identity($uid, $validity);
        $this->requireReviews();
        AiIntakeSchema::requireReady();
        $message = $this->imap->fetch($settings, $uid, $validity);
        $archivePaths = [];
        try {
            $this->unchanged($actor, $settings);
            abort_unless(($message['uid'] ?? null) === $uid && ($message['uid_validity'] ?? null) === $validity && ($message['mailbox_id'] ?? '') === AiDispositionSettings::mailboxId($settings), 409);

            return OperationsTransaction::run(function () use ($actor, $settings, $folder, $uid, $validity, $message, $asInquiry, &$archivePaths): AiIntake {
                $this->unchanged($actor, $settings);
                $review = DispositionMailboxReview::firstOrCreate($this->reviewIdentity($settings, $folder, $uid, $validity));
                $review = DispositionMailboxReview::lockForUpdate()->findOrFail($review->id);
                $intake = app(AiIntakeService::class)->receiveEmail($message, $actor);
                $source = $this->source($settings, $uid, $validity);
                if ($source) {
                    $archivePaths = array_merge($archivePaths, array_filter([$source->raw_path]), $source->attachments()->pluck('file_path')->all());
                }
                if ($asInquiry || $review->intake_id !== $intake->id) {
                    $review->update(['intake_id' => $intake->id, 'revision' => $review->revision + 1] + ($asInquiry ? ['classification' => 'inquiry', 'reviewed_by' => $actor->id] : []));
                    app(OperationsAuditService::class)->record($review, $actor, $asInquiry ? 'mailbox.import' : 'mailbox.capture_for_recognition', ['intake_id' => $intake->id]);
                }

                return $intake;
            });
        } finally {
            app(AiIntakeAttachmentService::class)->discard($archivePaths);
            Storage::disk('local')->delete($message['_cleanup'] ?? []);
        }
    }

    public function deliveries(User $actor, int $page = 1, string $search = ''): LengthAwarePaginator
    {
        $settings = $this->settings($actor);

        return $this->deliveryQuery($settings)->with('intake:id,title,status')->when($this->search($search) !== '', fn ($q) => $q->where('subject', 'like', '%'.$this->search($search).'%'))
            ->latest('id')->paginate(20, ['*'], 'mailboxDeliveriesPage', max(1, $page));
    }

    public function deliveryDetail(User $actor, int $id): array
    {
        $settings = $this->settings($actor);
        $delivery = $this->deliveryQuery($settings)->findOrFail($id);

        return ['id' => $delivery->id, 'intake_id' => $delivery->intake_id, 'subject' => $delivery->subject, 'to' => $delivery->recipient_email, 'from' => $settings['from_address'],
            'date' => ($delivery->sent_at ?? $delivery->created_at)?->toIso8601String(), 'text' => $delivery->body, 'status' => $delivery->status,
            'status_label' => ($delivery->metadata['manual_mailbox_test'] ?? false) ? 'Testentwurf · ohne Versand' : (AiIntakeDelivery::STATUS_LABELS[$delivery->status] ?? 'Versand prüfen'), 'type_label' => AiIntakeDelivery::TYPE_LABELS[$delivery->message_type ?? 'clarification'] ?? 'Nachricht', 'test_only' => (bool) ($delivery->metadata['manual_mailbox_test'] ?? false),
            'failure_code' => $delivery->failure_code, 'attachments' => [], 'source' => 'delivery'];
    }

    public function linkedIntake(User $actor, int $id): AiIntake
    {
        $settings = $this->settings($actor);
        AiIntakeSchema::requireReady();
        $intake = AiIntake::findOrFail($id);
        $indexed = Schema::hasColumn('ai_intake_messages', 'mailbox_id') && $intake->messages()->where('mailbox_id', AiDispositionSettings::mailboxId($settings))->exists();
        $linkedReview = Schema::hasTable('disposition_mailbox_reviews') && DispositionMailboxReview::where('mailbox_id', AiDispositionSettings::mailboxId($settings))->where('intake_id', $id)->exists();
        abort_unless($indexed || $linkedReview || $this->deliveryQuery($settings)->where('intake_id', $id)->exists(), 404);

        return $intake;
    }

    private function deliveryQuery(array $settings): Builder
    {
        AiIntakeSchema::requireReady();
        $mailbox = AiDispositionSettings::mailboxId($settings);

        return AiIntakeDelivery::query()->where(function ($q) use ($mailbox, $settings): void {
            if (Schema::hasColumn('ai_intake_deliveries', 'mailbox_id')) {
                $q->where('mailbox_id', $mailbox)->orWhere(function ($old) use ($mailbox, $settings): void {
                    $old->whereNull('mailbox_id')->where(function ($scope) use ($mailbox, $settings): void {
                        $scope->where('settings_revision', $settings['revision']);
                        if (Schema::hasColumn('ai_intake_messages', 'mailbox_id')) {
                            $scope->orWhereHas('message', fn ($m) => $m->where('mailbox_id', $mailbox));
                        }
                    });
                });
            } else {
                $q->where('settings_revision', $settings['revision']);
            }
        });
    }

    private function search(string $search): string
    {
        abort_if(mb_strlen($search) > 180 || preg_match('/[\r\n\x00]/', $search), 422);

        return trim($search);
    }

    private function folder(array $settings, string $folder): void
    {
        abort_unless($folder === $settings['imap_folder'] || ($folder !== '' && $folder === $this->imap->sentFolder($settings)), 404);
    }

    private function identity(int $uid, int $validity): void
    {
        abort_unless($uid > 0 && $validity > 0, 422);
    }

    private function requireReviews(): void
    {
        abort_unless(Schema::hasTable('disposition_mailbox_reviews'), 503, 'Die Postfacherweiterung muss zuerst eingerichtet werden.');
    }

    private function reviewIdentity(array $settings, string $folder, int $uid, int $validity): array
    {
        return ['mailbox_id' => AiDispositionSettings::mailboxId($settings), 'folder_hash' => hash('sha256', $folder), 'uid_validity' => $validity, 'uid' => $uid];
    }

    private function source(array $settings, int $uid, int $validity): ?AiIntakeMessage
    {
        if (! AiIntakeSchema::ready()) {
            return null;
        }
        $receipt = hash('sha256', json_encode([AiDispositionSettings::mailboxId($settings), (string) $validity, (string) $uid, null], JSON_THROW_ON_ERROR));

        return AiIntakeMessage::with('intake')->where('receipt_key', $receipt)->first();
    }

    private function classification(array $settings, string $folder, int $uid, int $validity, ?DispositionMailboxReview $review = null, ?AiIntakeMessage $source = null, bool $loaded = false): array
    {
        if (! $loaded) {
            $review ??= Schema::hasTable('disposition_mailbox_reviews') ? DispositionMailboxReview::where($this->reviewIdentity($settings, $folder, $uid, $validity))->first() : null;
            $source ??= $this->source($settings, $uid, $validity);
        }
        $intake = $source?->intake;
        $analysis = $intake?->analysis ?? [];
        $classification = $review?->reviewed_by ? $review->classification : null;
        if (! $classification) {
            $classification = match (true) {
                ($analysis['intent'] ?? '') === 'inquiry' && in_array($analysis['classification'] ?? '', ['new_inquiry', 'reply'], true) => 'inquiry',
                in_array($analysis['classification'] ?? '', ['unrelated', 'auto_reply'], true) => 'not_inquiry',
                default => 'unreviewed',
            };
        }

        return ['classification' => $classification, 'classification_label' => DispositionMailboxReview::LABELS[$classification] ?? 'Ungeprüft',
            'classification_source' => $review?->reviewed_by ? 'manual' : ($classification === 'unreviewed' ? 'pending' : 'ai'), 'intake_id' => $intake?->id, 'intake_status' => $intake?->status];
    }

    private function decorate(array $result, array $settings): array
    {
        // Only the visible page is decrypted; raw bodies and credentials never enter list state.
        $uids = array_column($result['messages'], 'uid');
        $keys = [];
        foreach ($uids as $uid) {
            $keys[$uid] = hash('sha256', json_encode([AiDispositionSettings::mailboxId($settings), (string) $result['uid_validity'], (string) $uid, null], JSON_THROW_ON_ERROR));
        }
        $reviews = Schema::hasTable('disposition_mailbox_reviews') ? DispositionMailboxReview::where('mailbox_id', AiDispositionSettings::mailboxId($settings))
            ->where('folder_hash', hash('sha256', $result['folder']))->where('uid_validity', $result['uid_validity'])->whereIn('uid', $uids)->get()->keyBy('uid') : collect();
        $sources = AiIntakeSchema::ready() ? AiIntakeMessage::with('intake')->whereIn('receipt_key', array_values($keys))->get()->keyBy('receipt_key') : collect();
        foreach ($result['messages'] as &$message) {
            $message += $this->classification($settings, $result['folder'], (int) $message['uid'], (int) $result['uid_validity'], $reviews->get($message['uid']), $sources->get($keys[$message['uid']]), true);
        }

        return $result;
    }
}
