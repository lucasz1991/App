<?php

namespace App\Services\Operations;

use App\Jobs\DeliverAiIntakeMail;
use App\Models\AiIntake;
use App\Models\AiIntakeDelivery;
use App\Models\AiIntakeMessage;
use App\Models\AiIntakeRun;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Support\Operations\AiDispositionSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AiIntakeMailService
{
    public function __construct(private readonly AiDispositionSmtpTransport $transport) {}

    public function enqueueClarification(AiIntake $intake, array $questions, ?AiIntakeRun $run = null): ?AiIntakeDelivery
    {
        return DB::transaction(function () use ($intake, $questions, $run): ?AiIntakeDelivery {
            $settings = AiDispositionSettings::all(true);
            $fresh = AiIntake::lockForUpdate()->findOrFail($intake->id);
            $contact = $this->verifiedContact($fresh, $settings);
            if ($run !== null && ((int) $run->settings_revision !== (int) $settings['revision'] || $run->source_revision !== $fresh->source_revision)) {
                return null;
            }
            if (! $settings['enabled'] || $settings['automation_mode'] !== 'automatic' || ! $contact || $fresh->paused_at || $fresh->question_round >= $settings['max_rounds']) {
                return null;
            }
            $message = AiIntakeMessage::find($fresh->latest_inbound_message_id);
            $prompts = [
                'starts_at' => 'An welchem Datum und um welche Uhrzeit beginnt der Einsatz?',
                'ends_at' => 'An welchem Datum und um welche Uhrzeit endet der Einsatz?',
                'location_name' => 'An welchem Einsatzort findet der Einsatz statt?',
                'role_name' => 'Welche Funktion oder Leistung wird benötigt?',
                'required_staff' => 'Wie viele Mitarbeitende werden benötigt?',
                'timezone' => 'In welcher Zeitzone gelten die angegebenen Einsatzzeiten?',
                'segments' => 'Welche einzelnen Einsatzabschnitte und Zeiten gehören zur Anfrage?',
                'qualification_ids' => 'Welche Qualifikationen sind für den Einsatz erforderlich?',
            ];
            $questionText = collect($questions)->map(function ($question, $key) use ($prompts, $fresh): ?string {
                $field = is_array($question) ? (string) ($question['field'] ?? '') : (is_string($key) ? $key : (string) $question);
                if (! isset($prompts[$field])) {
                    return null;
                }
                if (is_array($question) && array_key_exists('position', $question)) {
                    $position = filter_var($question['position'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);

                    if ($position === false) {
                        return null;
                    }
                    $proposal = $fresh->proposals()->where('source_revision', $fresh->source_revision)->where('position_index', $position - 1)->first();
                    $demand = (array) ($proposal?->payload['demand'] ?? []);
                    $evidence = (array) ($proposal?->payload['evidence'] ?? []);
                    $labels = [];
                    foreach (['role_name' => 'Funktion', 'location_name' => 'Ort'] as $name => $label) {
                        if (! empty($evidence[$name]) && is_string($demand[$name] ?? null)) {
                            $value = mb_substr(trim(preg_replace('/[\x00-\x1F\x7F]/u', ' ', $demand[$name])), 0, 80);
                            $labels[] = $label.': „'.$value.'“';
                        }
                    }

                    return 'Leistung '.$position.($labels ? ' ('.implode(' · ', $labels).')' : '').': '.$prompts[$field];
                }

                return $prompts[$field];
            })->filter()->unique()->values();
            if ($questionText->isEmpty()) {
                return null;
            }
            $round = $fresh->question_round + 1;
            $dedup = hash('sha256', 'clarification|'.$fresh->id.'|'.$fresh->source_revision);
            $domain = substr(strrchr($settings['from_address'], '@') ?: '@railtime.invalid', 1);
            $metadata = (array) ($message?->metadata ?? []);
            $references = $this->messageIds($metadata['references'] ?? []);
            $parent = $this->messageIds([$message?->external_message_id]);
            $delivery = AiIntakeDelivery::firstOrCreate(['dedup_key' => $dedup], [
                'intake_id' => $fresh->id, 'message_id' => $message?->id, 'customer_id' => $fresh->customer_id, 'contact_id' => $contact->id,
                'recipient_email' => strtolower(trim($contact->email)), 'subject' => mb_substr('Rückfrage zu Ihrer Anfrage · '.$fresh->public_id, 0, 180),
                'body' => "Guten Tag,\n\nfür die Bearbeitung Ihrer Anfrage fehlen noch folgende Angaben:\n\n".$questionText->map(fn ($q) => '- '.$q)->implode("\n")."\n\nBitte antworten Sie auf diese E-Mail. Ihre Anfrage ist noch keine verbindliche Auftragsbestätigung.\n\n".$settings['from_name'],
                'status' => 'pending', 'settings_revision' => $settings['revision'], 'intake_revision' => $fresh->revision,
                'source_revision' => $fresh->source_revision, 'question_round' => $round, 'attempts' => 0,
                'message_id_header' => 'ai-'.Str::uuid().'@'.$domain, 'in_reply_to' => $parent[0] ?? null,
                'references' => array_slice(array_values(array_unique([...$references, ...$parent])), -20),
                'metadata' => ['run_id' => $run?->id, 'contact_revision' => (int) $contact->revision, 'supervisor_id' => $settings['supervisor_id']],
            ]);
            if ($delivery->wasRecentlyCreated) {
                $fresh->forceFill(['question_round' => $round, 'status' => 'waiting_customer', 'revision' => $fresh->revision + 1])->save();
                $delivery->forceFill(['intake_revision' => $fresh->revision])->save();
                DeliverAiIntakeMail::dispatch($delivery->id)->afterCommit();
            }

            return in_array($delivery->status, ['canceled', 'unknown'], true) ? null : $delivery;
        });
    }

    private function verifiedContact(AiIntake $intake, array $settings): ?CustomerContact
    {
        if (! AiDispositionSettings::supervisor($settings) || (int) $intake->supervising_user_id !== (int) $settings['supervisor_id'] || $intake->match_method !== 'contact_email' || $intake->source_type !== 'email') {
            return null;
        }
        $customer = Customer::find($intake->customer_id);
        $contact = CustomerContact::where('customer_id', $intake->customer_id)->where('is_active', true)->find($intake->customer_contact_id);
        $message = AiIntakeMessage::where('intake_id', $intake->id)->where('direction', 'inbound')->find($intake->latest_inbound_message_id);
        if (! $customer?->is_active || ! $contact || ! $message) {
            return null;
        }
        $email = strtolower(trim($contact->email));
        $metadata = (array) $message->metadata;
        if (! empty($metadata['source_error']) || ($metadata['raw_archive_complete'] ?? true) === false) {
            return null;
        }
        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || ! hash_equals($email, strtolower(trim((string) $message->sender_email))) || ($message->reply_to_email && ! hash_equals($email, strtolower(trim($message->reply_to_email))))) {
            return null;
        }
        if (! in_array(strtolower(trim((string) ($metadata['auto_submitted'] ?? ''))), ['', 'no'], true) || in_array(strtolower((string) ($metadata['precedence'] ?? '')), ['bulk', 'junk', 'list'], true) || ($metadata['list_id'] ?? '') !== '' || ($metadata['is_bounce'] ?? false) || hash_equals($email, strtolower($settings['from_address']))) {
            return null;
        }
        // Duplicate contacts with the same sender are an ambiguous customer assignment.
        $matching = CustomerContact::where('is_active', true)->whereRaw('LOWER(TRIM(email)) = ?', [$email])->count();

        return $matching === 1 ? $contact : null;
    }

    public function deliver(int $deliveryId): string
    {
        $base = AiIntakeDelivery::findOrFail($deliveryId);
        $claim = DB::transaction(function () use ($deliveryId, $base): ?array {
            $intake = AiIntake::lockForUpdate()->findOrFail($base->intake_id);
            $delivery = AiIntakeDelivery::lockForUpdate()->findOrFail($deliveryId);
            if ($delivery->status !== 'pending') {
                return null;
            }
            abort_unless($delivery->intake_id === $intake->id, 409);
            $settings = AiDispositionSettings::all(true);
            $contact = $this->verifiedContact($intake, $settings);
            if (! $settings['enabled'] || $settings['automation_mode'] !== 'automatic' || (int) $settings['revision'] !== $delivery->settings_revision || ! $contact || $intake->paused_at || in_array($intake->status, ['paused', 'completed', 'failed'], true) || $intake->source_revision !== $delivery->source_revision || $intake->revision !== $delivery->intake_revision || $intake->latest_inbound_message_id !== $delivery->message_id || $delivery->question_round > $settings['max_rounds'] || $contact->revision !== (int) ($delivery->metadata['contact_revision'] ?? 0) || ! hash_equals(strtolower(trim($contact->email)), $delivery->recipient_email)) {
                $delivery->forceFill(['status' => 'canceled', 'failure_code' => 'context_changed'])->save();
                if ($intake->status === 'waiting_customer' && $intake->source_revision === $delivery->source_revision) {
                    $intake->forceFill(['status' => 'review', 'error_code' => 'clarification_canceled', 'revision' => $intake->revision + 1])->save();
                }

                return null;
            }
            $delivery->forceFill(['status' => 'sending', 'attempts' => $delivery->attempts + 1, 'attempted_at' => now()->utc()])->save();

            return ['delivery' => $delivery, 'settings' => $settings];
        }, 3);
        if ($claim === null) {
            return AiIntakeDelivery::findOrFail($deliveryId)->status;
        }
        try {
            $this->transport->send($claim['delivery'], $claim['settings']);
            DB::transaction(function () use ($deliveryId, $claim): void {
                AiIntake::lockForUpdate()->findOrFail($claim['delivery']->intake_id);
                $delivery = AiIntakeDelivery::lockForUpdate()->findOrFail($deliveryId);
                $delivery->forceFill(['status' => 'sent', 'sent_at' => now()->utc(), 'failure_code' => null])->save();
                AiIntakeMessage::firstOrCreate(['intake_id' => $delivery->intake_id, 'direction' => 'outbound', 'external_message_id' => $delivery->message_id_header], ['sender_email' => $claim['settings']['from_address'], 'body' => $delivery->body, 'metadata' => ['delivery_id' => $delivery->id, 'in_reply_to' => $delivery->in_reply_to, 'references' => $delivery->references]]);
            });

            return 'sent';
        } catch (\Throwable) {
            $this->markUnknown($deliveryId, 'transport_outcome_unknown');

            return 'unknown';
        }
    }

    public function expireAwaitingReplies(): int
    {
        $settings = AiDispositionSettings::all(true);
        if (! $settings['enabled']) {
            return 0;
        }
        foreach (AiIntakeDelivery::where('status', 'sending')->where('attempted_at', '<', now()->utc()->subMinutes(10))->orderBy('id')->limit(100)->pluck('id') as $id) {
            $this->markUnknown((int) $id, 'worker_outcome_unknown');
        }
        $ids = AiIntake::where('status', 'waiting_customer')->whereHas('deliveries', fn ($query) => $query->where('status', 'sent')->where('sent_at', '<', now()->utc()->subHours((int) $settings['reply_timeout_hours'])))
            ->whereDoesntHave('deliveries', fn ($query) => $query->where('status', 'sent')->where('sent_at', '>=', now()->utc()->subHours((int) $settings['reply_timeout_hours'])))->orderBy('id')->limit(100)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            $count += DB::transaction(function () use ($id, $settings): int {
                $intake = AiIntake::lockForUpdate()->find($id);
                $latest = $intake?->deliveries()->where('status', 'sent')->latest('sent_at')->first();
                if (! $intake || $intake->status !== 'waiting_customer' || $intake->paused_at || ! $latest || CarbonImmutable::parse($latest->getRawOriginal('sent_at'), 'UTC') >= now()->utc()->subHours((int) $settings['reply_timeout_hours'])) {
                    return 0;
                }
                $intake->forceFill(['status' => 'review', 'error_code' => 'customer_reply_timeout', 'revision' => $intake->revision + 1])->save();

                return 1;
            });
        }

        return $count;
    }

    private function markUnknown(int $deliveryId, string $failure): void
    {
        $base = AiIntakeDelivery::find($deliveryId);
        if (! $base) {
            return;
        }
        DB::transaction(function () use ($base, $failure): void {
            $intake = AiIntake::lockForUpdate()->find($base->intake_id);
            $delivery = AiIntakeDelivery::lockForUpdate()->find($base->id);
            if (! $delivery || $delivery->status !== 'sending') {
                return;
            }
            $delivery->forceFill(['status' => 'unknown', 'failure_code' => $failure])->save();
            if ($intake?->status === 'waiting_customer' && $intake->source_revision === $delivery->source_revision) {
                $intake->forceFill(['status' => 'review', 'error_code' => 'delivery_outcome_unknown', 'revision' => $intake->revision + 1])->save();
            }
        }, 3);
    }

    private function messageIds(mixed $ids): array
    {
        $ids = is_array($ids) ? $ids : preg_split('/\s+/', (string) $ids);

        return array_values(array_filter(array_map(fn ($id) => trim((string) $id, '<> '), $ids), fn ($id) => preg_match('/^[^\s<>\r\n]+@[^\s<>\r\n]+$/D', $id) && strlen($id) < 191));
    }
}
