<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeMessage;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\Order;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\AiIntakeSchema;
use App\Support\Operations\OperationsAccess;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Webklex\PHPIMAP\Message;

/** Readonly identity and history hints. Never extraction evidence or a customer assignment. */
class AiIntakeCustomerContextService
{
    public const VERSION = 'customer-context-v1';

    public const REASON_LABELS = [
        'linked_customer' => 'Bereits zugeordneter Kunde', 'sender_email' => 'Absenderadresse stimmt überein',
        'sender_domain' => 'E-Mail-Domain stimmt überein', 'cc_email' => 'Adresse in CC stimmt überein',
        'cc_domain' => 'Firmendomain in CC stimmt überein', 'forwarded_email' => 'Absender im weitergeleiteten Original stimmt überein',
        'forwarded_domain' => 'Firmendomain im weitergeleiteten Original stimmt überein',
        'body_email' => 'E-Mail-Adresse im Inhalt stimmt überein', 'body_domain' => 'Firmendomain im Inhalt stimmt überein',
        'signature_email' => 'E-Mail-Adresse in der Signatur stimmt überein', 'signature_domain' => 'Firmendomain in der Signatur stimmt überein',
        'company_name_exact' => 'Firmenname stimmt im Original überein', 'signature_company' => 'Firmenname in der Signatur stimmt überein',
        'body_company' => 'Firmenname im Inhalt stimmt überein',
        'same_sender' => 'Gleicher Absender', 'same_cc' => 'Gleiche Adresse in CC', 'same_forwarded_sender' => 'Gleicher Absender im weitergeleiteten Original',
        'mail_reference' => 'Verweis auf eine frühere Mail', 'order_reference' => 'Auftragsnummer im Original genannt',
        'same_location' => 'Gleicher Einsatzort', 'same_title' => 'Gleiche Leistungsbezeichnung', 'same_role' => 'Gleiche Tätigkeit',
        'recent_customer_record' => 'Früherer Datensatz des Kunden', 'same_customer_mailbox' => 'Gleicher Kunde und gleiches Postfach',
    ];

    private const PUBLIC_DOMAINS = [
        'gmail.com', 'googlemail.com', 'outlook.com', 'outlook.de', 'hotmail.com', 'hotmail.de', 'live.com', 'live.de', 'msn.com',
        'yahoo.com', 'yahoo.de', 'yahoo.co.uk', 'ymail.com', 'rocketmail.com', 'icloud.com', 'me.com', 'mac.com',
        'gmx.de', 'gmx.net', 'gmx.com', 'gmx.at', 'gmx.ch', 'web.de', 't-online.de', 'freenet.de', 'arcor.de', 'vodafone.de',
        'mail.de', 'email.de', 'mail.com', 'email.com', 'mailbox.org', 'posteo.de', 'posteo.net', 'proton.me', 'protonmail.com', 'pm.me',
        'aol.com', 'aol.de', 'zoho.com', 'yandex.com', 'yandex.ru', 'mail.ru', 'rambler.ru', 'qq.com', '163.com', '126.com',
    ];

    public function candidates(AiIntake $intake, User $actor): array
    {
        return $this->context($intake, $actor)['candidates'];
    }

    /** Metadata is server-created, outside any model-returned analysis. */
    public function context(AiIntake $intake, User $actor): array
    {
        [$current, $actor, $snapshot, $message, $settings] = $this->begin($intake, $actor);
        [$text, $signals, $sourceAvailable] = $this->source($message);
        $text = $this->withoutBlindCopies($text);
        $signature = implode("\n", array_slice(explode("\n", $text), -12));
        $internalDomains = $internalEmails = [];
        foreach ([$settings['from_address'], $settings['imap_username'], config('mail.from.address', '')] as $address) {
            if ($email = $this->email($address)) {
                $internalEmails[] = $email;
                if (! in_array($this->domain($email), self::PUBLIC_DOMAINS, true)) {
                    $internalDomains[] = $this->domain($email);
                }
            }
        }
        $signals = [...$signals, ...$this->bodySignals($text, $signature)];
        $signalEmails = array_values(array_unique(array_column($signals, 'value')));
        $internalEmails = array_merge($internalEmails, User::whereIn('email', $signalEmails)->pluck('email')->map(fn ($value) => $this->email($value))->filter()->all());
        $signals = array_values(array_filter($signals, fn ($signal) => ! in_array($signal['value'], $internalEmails, true) && ! in_array($this->domain($signal['value']), $internalDomains, true)));
        $customers = Customer::active()->orderBy('id')->limit(2001)->get(['id', 'company_name', 'email']);
        $lookupTruncated = $customers->count() > 2000;
        $customers = $customers->take(2000)->keyBy('id');
        if ($current->customer_id && ($linked = Customer::find($current->customer_id))) {
            $customers->put($linked->id, $linked);
        }
        $contacts = Schema::hasTable('customer_contacts') ? CustomerContact::where('is_active', true)->whereIn('customer_id', $customers->keys())->orderBy('id')->limit(4001)->get(['customer_id', 'email']) : collect();
        $lookupTruncated = $lookupTruncated || $contacts->count() > 4000;
        $contacts = $contacts->take(4000)->groupBy('customer_id');
        $draftCompany = $this->normalized((string) ($current->analysis['customer_draft']['company_name'] ?? ''));
        $result = [];
        foreach ($customers as $customer) {
            if ($customer->id !== $current->customer_id && in_array($this->domain($this->email($customer->email)), $internalDomains, true)) {
                continue;
            }
            $matches = [];
            $score = 0.0;
            if ($customer->id === $current->customer_id) {
                $matches[] = ['source' => 'linked', 'value' => (string) $customer->id, 'match' => 'linked_customer', 'message_id' => null];
                $score = 1.0;
            }
            $addresses = [$this->email($customer->email), ...($contacts->get($customer->id, collect())->pluck('email')->map(fn ($value) => $this->email($value))->all())];
            $addresses = array_values(array_filter(array_unique($addresses)));
            foreach ($signals as $signal) {
                $exact = in_array($signal['value'], $addresses, true);
                $domain = $this->domain($signal['value']);
                $domainMatch = ! in_array($domain, self::PUBLIC_DOMAINS, true) && in_array($domain, array_map($this->domain(...), $addresses), true);
                if (! $exact && ! $domainMatch) {
                    continue;
                }
                $reason = $signal['source'].'_'.($exact ? 'email' : 'domain');
                $matches[] = [...$signal, 'match' => $reason, 'message_id' => $message?->id];
                $weight = $exact ? ['sender' => .98, 'forwarded' => .95, 'cc' => .86, 'signature' => .84, 'body' => .78][$signal['source']] : ['sender' => .72, 'forwarded' => .70, 'cc' => .60, 'signature' => .58, 'body' => .52][$signal['source']];
                $score = max($score, $weight);
            }
            if ($sourceAvailable && ($literal = $this->literalName($text, $customer->company_name))) {
                $reason = $draftCompany === $this->normalized($customer->company_name) ? 'company_name_exact' : ($this->literalName($signature, $customer->company_name) ? 'signature_company' : 'body_company');
                array_unshift($matches, ['source' => $reason === 'signature_company' ? 'signature' : 'body', 'value' => $literal, 'match' => $reason, 'message_id' => $message?->id]);
                $score = max($score, $reason === 'company_name_exact' ? .94 : .76);
            }
            if ($matches !== []) {
                $result[] = ['customer_id' => (int) $customer->id, 'name' => $customer->company_name, 'confidence' => $score,
                    'reasons' => array_values(array_unique(array_column($matches, 'match'))), 'matched_signals' => $matches];
            }
        }
        usort($result, fn ($a, $b) => $b['confidence'] <=> $a['confidence'] ?: $a['customer_id'] <=> $b['customer_id']);
        $ambiguous = count($result) > 1;
        foreach ($result as &$candidate) {
            $candidate['ambiguous'] = $ambiguous;
        }
        unset($candidate);
        $this->assertCurrent($current, $actor, $snapshot);

        return ['version' => self::VERSION, 'intake_id' => $current->id, 'intake_revision' => $current->revision, 'source_revision' => $current->source_revision,
            'settings_revision' => $snapshot['settings_revision'], 'mailbox_id' => $snapshot['mailbox_id'], 'message_id' => $message?->id,
            'source_hash' => hash('sha256', $text), 'source_available' => $sourceAvailable, 'candidates' => array_slice($result, 0, 10),
            'ambiguous' => $ambiguous, 'lookup_truncated' => $lookupTruncated || count($result) > 10, 'source_snapshot' => $snapshot];
    }

    public function history(AiIntake $intake, User $actor, int $allowedCandidateId): array
    {
        $context = $this->context($intake, $actor);
        $candidate = collect($context['candidates'])->firstWhere('customer_id', $allowedCandidateId);
        abort_unless($candidate, 403, 'Dieser Kunde gehört nicht zum aktuellen Prüfkontext.');
        [$current, $actor, $snapshot, $message] = $this->begin($intake, $actor);
        abort_unless($context['source_snapshot'] === $snapshot, 409, 'Der Prüfkontext wurde geändert.');
        [$text] = $this->source($message);
        $text = $this->withoutBlindCopies($text);
        $ordersAllowed = $actor->can('operations.manage');
        $orders = collect();
        if ($ordersAllowed) {
            $orders = Order::where('customer_id', $allowedCandidateId)->when($message, fn ($query) => $query->where('created_at', '<=', $message->created_at))
                ->latest('id')->limit(101)->get(['id', 'order_number', 'title', 'service_type', 'starts_at', 'ends_at', 'timezone', 'location_name', 'required_staff', 'status']);
        }
        $orderRows = $orders->take(100)->map(function ($order) use ($text): array {
            [$score, $reasons] = $this->relevance($text, $order->order_number, $order->title, $order->location_name, $order->service_type);

            return ['id' => $order->id, 'number' => $order->order_number, 'title' => $order->title, 'service_type' => $order->service_type,
                'starts_at' => $order->starts_at?->setTimezone($order->timezone)->format('Y-m-d\TH:i'), 'ends_at' => $order->ends_at?->setTimezone($order->timezone)->format('Y-m-d\TH:i'), 'timezone' => $order->timezone,
                'location_name' => $order->location_name, 'required_staff' => $order->required_staff, 'status' => $order->status?->value,
                'relevance' => $score, 'reasons' => $reasons];
        })->sort(fn ($a, $b) => $b['relevance'] <=> $a['relevance'] ?: $b['id'] <=> $a['id'])->values();
        $mailbox = $snapshot['mailbox_id'];
        $messages = collect();
        if ($message && $mailbox !== '') {
            $messages = AiIntakeMessage::where('direction', 'inbound')->where('id', '<', $message->id)->where('intake_id', '!=', $current->id)
                ->whereHas('intake', fn ($query) => $query->where(fn ($scope) => $scope->where('customer_id', $allowedCandidateId)->orWhereNull('customer_id'))->where('source_type', 'email')->where('status', '!=', 'not_inquiry'))
                ->when(Schema::hasColumn('ai_intake_messages', 'mailbox_id'), fn ($query) => $query->where('mailbox_id', $mailbox))
                ->with('intake:id,public_id,title,status,customer_id,revision,source_revision,processed_source_revision,analysis')->latest('id')->limit(31)->get();
            $messages = $messages->filter(fn ($related) => (string) ($related->metadata['mailbox_id'] ?? $related->mailbox_id ?? '') === $mailbox);
        }
        $candidateEmails = array_filter(array_map($this->email(...), array_column($candidate['matched_signals'], 'value')));
        $candidateEmails[] = $this->email(Customer::findOrFail($allowedCandidateId)->email);
        if (Schema::hasTable('customer_contacts')) {
            $candidateEmails = [...$candidateEmails, ...CustomerContact::where('customer_id', $allowedCandidateId)->where('is_active', true)->pluck('email')->map($this->email(...))->all()];
        }
        $candidateEmails = array_values(array_filter(array_unique($candidateEmails)));
        $references = $this->references($message?->metadata ?? []);
        $mailRows = $messages->take(30)->map(function ($related) use ($text, $references, $candidateEmails, $allowedCandidateId): ?array {
            $record = $related->intake;
            [$relatedText, $signals, $available] = $this->source($related);
            if (! $available) {
                return null;
            }
            $relationship = [];
            foreach ($signals as $signal) {
                if (in_array($signal['value'], $candidateEmails, true)) {
                    $relationship[] = $signal['source'] === 'cc' ? 'same_cc' : 'same_sender';
                }
            }
            foreach ($this->bodySignals($this->withoutBlindCopies($relatedText), '') as $signal) {
                if ($signal['source'] === 'forwarded' && in_array($signal['value'], $candidateEmails, true)) {
                    $relationship[] = 'same_forwarded_sender';
                }
            }
            if ($record->customer_id !== $allowedCandidateId && $relationship === []) {
                return null;
            }
            $positions = [];
            if ($record->processed_source_revision === $record->source_revision) {
                foreach (array_slice((array) ($record->analysis['positions'] ?? []), 0, 5) as $position) {
                    $positions[] = array_intersect_key($position['demand'] ?? $position, array_flip(['title', 'role_name', 'location_name', 'starts_at', 'ends_at', 'timezone', 'required_staff']));
                }
            }
            [$score, $reasons] = $this->relevance($text, null, $record->title, $positions[0]['location_name'] ?? null, $positions[0]['role_name'] ?? null);
            if (in_array(trim((string) $related->external_message_id, '<> '), $references, true)) {
                $score += 100;
                array_unshift($reasons, 'mail_reference');
            }

            return ['intake_id' => $record->id, 'message_id' => $related->id, 'subject' => mb_substr((string) ($related->metadata['subject'] ?? $record->title), 0, 180),
                'date' => $related->created_at?->toIso8601String(), 'status' => $record->status, 'positions' => $positions, 'relevance' => $score,
                'intake_revision' => $record->revision, 'source_revision' => $record->source_revision, 'customer_id' => $record->customer_id,
                'source_hash' => $this->messageHash($related), 'reasons' => array_values(array_unique([...$relationship, ...$reasons]))];
        })->filter()->sort(fn ($a, $b) => $b['relevance'] <=> $a['relevance'] ?: $b['message_id'] <=> $a['message_id'])->values();
        $this->assertCurrent($current, $actor, $snapshot);

        return ['customer' => ['id' => $allowedCandidateId, 'name' => $candidate['name']], 'orders' => $orderRows->take(5)->all(), 'related_mails' => $mailRows->take(5)->all(),
            'provenance' => ['kind' => 'historical_hint', 'version' => self::VERSION, 'source_snapshot' => $snapshot],
            'truncated' => ['orders' => $orders->count() > 5, 'related_mails' => $messages->count() > 5],
            'permissions' => ['orders' => $ordersAllowed, 'related_mails' => true], 'source_available' => $context['source_available']];
    }

    private function begin(AiIntake $intake, User $actor): array
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        AiIntakeSchema::requireReady();
        $current = AiIntake::findOrFail($intake->id);
        abort_unless($current->revision === $intake->revision && $current->source_revision === $intake->source_revision, 409, 'Der Prüfkontext wurde geändert.');
        $message = $current->messages()->where('direction', 'inbound')->whereKey($current->latest_inbound_message_id)->first();
        $settings = AiDispositionSettings::all(true);

        return [$current, $actor, $this->snapshot($current, $message, (int) $settings['revision'], $actor->can('operations.manage')), $message, $settings];
    }

    private function snapshot(AiIntake $intake, ?AiIntakeMessage $message, int $settingsRevision, bool $ordersAllowed): array
    {
        return ['intake_id' => $intake->id, 'revision' => $intake->revision, 'source_revision' => $intake->source_revision, 'customer_id' => $intake->customer_id,
            'message_id' => $message?->id, 'mailbox_id' => (string) ($message?->metadata['mailbox_id'] ?? $message?->mailbox_id ?? ''), 'settings_revision' => $settingsRevision,
            'orders_allowed' => $ordersAllowed,
            'message_hash' => $this->messageHash($message)];
    }

    private function messageHash(?AiIntakeMessage $message): string
    {
        return hash('sha256', json_encode([$message?->body, $message?->sender_email, $message?->raw_hash, $message?->metadata], JSON_THROW_ON_ERROR));
    }

    private function assertCurrent(AiIntake $intake, User $actor, array $snapshot): void
    {
        [, , $fresh] = $this->begin($intake, $actor);
        abort_unless(hash_equals(hash('sha256', json_encode($snapshot, JSON_THROW_ON_ERROR)), hash('sha256', json_encode($fresh, JSON_THROW_ON_ERROR))), 409, 'Der Prüfkontext wurde geändert.');
    }

    private function source(?AiIntakeMessage $message): array
    {
        if (! $message) {
            return ['', [], false];
        }
        try {
            $text = app(AiIntakeMessageContent::class)->forMessage($message);
            $signals = [];
            if ($message->raw_path) {
                $raw = app(AiIntakeAttachmentService::class)->bytes($message->intake, $message->raw_path, $message->raw_hash);
                $headers = preg_split('/\r?\n\r?\n/', $raw, 2)[0];
                $parsed = Message::fromString($headers."\r\n\r\n");
                foreach (['sender' => $parsed->getFrom(), 'cc' => $parsed->getCc()] as $kind => $addresses) {
                    foreach (array_slice($addresses->all(), 0, 32) as $address) {
                        if ($email = $this->email($address->mail ?? '')) {
                            $signals[] = ['source' => $kind, 'value' => $email];
                        }
                    }
                }
            } elseif ($email = $this->email($message->sender_email)) {
                $signals[] = ['source' => 'sender', 'value' => $email];
            }

            return [$text, $signals, true];
        } catch (HttpException $error) {
            if (! in_array($error->getStatusCode(), [404, 409], true)) {
                throw $error;
            }
        } catch (RuntimeException $error) {
            if (! in_array($error->getMessage(), ['mailbox_content_invalid', 'mailbox_content_limit'], true)) {
                throw $error;
            }
        }

        // Fail closed for inferred identity; never replace a corrupt archive with its flattened body.
        return ['', [], false];
    }

    private function bodySignals(string $text, string $signature): array
    {
        $signals = [];
        $lines = explode("\n", $text);
        foreach ($lines as $index => $line) {
            $forwarded = preg_match('/^\s*(?:>\s*)*(?:From|Von)\s*:/iu', $line)
                && preg_match('/(?:Forwarded message|Weitergeleitete Nachricht|Original Message|Ursprüngliche Nachricht|(?:Sent|Gesendet|Date|Datum|To|An|Subject|Betreff)\s*:)/iu', implode("\n", array_slice($lines, max(0, $index - 3), 9)));
            foreach ($this->emailsIn($line) as $email) {
                $kind = $forwarded ? 'forwarded' : (str_contains($signature, $line) ? 'signature' : 'body');
                $signals[$kind.'|'.$email] = ['source' => $kind, 'value' => $email];
            }
        }

        return array_slice(array_values($signals), 0, 64);
    }

    private function withoutBlindCopies(string $text): string
    {
        $result = [];
        $blind = false;
        foreach (explode("\n", $text) as $line) {
            if (preg_match('/^\s*(?:>\s*)*(?:Bcc|Blindkopie|Blinde Kopie)\s*:/iu', $line)) {
                $blind = true;

                continue;
            }
            if ($blind && (preg_match('/^\s+/', $line) || preg_replace('/[\s,;<>]/', '', preg_replace('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '', $line)) === '')) {
                continue;
            }
            $blind = false;
            $result[] = $line;
        }

        return implode("\n", $result);
    }

    private function emailsIn(string $text): array
    {
        preg_match_all('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $text, $matches);

        return array_values(array_filter(array_unique(array_map($this->email(...), $matches[0]))));
    }

    private function email(mixed $value): string
    {
        $value = mb_strtolower(trim((string) $value));

        return filter_var($value, FILTER_VALIDATE_EMAIL) ? $value : '';
    }

    private function domain(string $email): string
    {
        return str_contains($email, '@') ? (string) substr(strrchr($email, '@'), 1) : '';
    }

    private function normalized(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $value)));
    }

    private function literalName(string $source, ?string $name): ?string
    {
        $name = trim((string) $name);
        if (mb_strlen($name) < 3 || mb_strlen($name) > 180) {
            return null;
        }
        $parts = preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY);
        $pattern = '/(?<![\pL\pN])'.implode('\s+', array_map(fn ($part) => preg_quote($part, '/'), $parts)).'(?![\pL\pN])/iu';

        return preg_match($pattern, $source, $match) === 1 ? $match[0] : null;
    }

    private function relevance(string $source, ?string $reference, ?string $title, ?string $location, ?string $role): array
    {
        $score = 0;
        $reasons = [];
        foreach (['order_reference' => [$reference, 100], 'same_location' => [$location, 40], 'same_title' => [$title, 30], 'same_role' => [$role, 20]] as $key => [$value, $weight]) {
            if ($value && $this->literalName($source, $value)) {
                $score += $weight;
                $reasons[] = $key;
            }
        }

        return [$score, $reasons === [] ? ['recent_customer_record'] : $reasons];
    }

    private function references(array $metadata): array
    {
        $references = $metadata['references'] ?? [];
        $references = is_array($references) ? $references : preg_split('/\s+/', (string) $references);

        return array_values(array_filter(array_map(fn ($value) => trim((string) $value, '<> '), [...$references, $metadata['in_reply_to'] ?? ''])));
    }
}
