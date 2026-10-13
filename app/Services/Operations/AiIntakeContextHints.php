<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeMessage;
use App\Models\Order;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;

/** Presents server-generated history snapshots only within the current actor and source scope. */
final class AiIntakeContextHints
{
    public function forReview(AiIntake $intake, User $actor): array
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        $hints = $intake->analysis['context_hints'] ?? [];
        if (($hints['provenance']['kind'] ?? '') !== 'historical_hint'
            || ($hints['provenance']['intake_id'] ?? null) !== $intake->id
            || ($hints['provenance']['source_revision'] ?? null) !== $intake->source_revision
            || ($hints['provenance']['settings_revision'] ?? null) !== (int) AiDispositionSettings::all()['revision']) {
            return [];
        }
        $context = app(AiIntakeCustomerContextService::class)->context($intake, $actor);
        $allowed = array_column($context['candidates'], 'customer_id');
        $history = [];
        foreach (array_slice($hints['history'] ?? [], 0, 2) as $entry) {
            $customerId = (int) ($entry['customer']['id'] ?? 0);
            if (! in_array($customerId, $allowed, true)
                || ($entry['provenance']['source_snapshot']['message_hash'] ?? null) !== $context['source_snapshot']['message_hash']) {
                continue;
            }
            $orderIds = $actor->can('operations.manage')
                ? Order::where('customer_id', $customerId)->whereIn('id', array_column($entry['orders'] ?? [], 'id'))->pluck('id')->all() : [];
            $entry['orders'] = array_values(array_filter($entry['orders'] ?? [], fn ($order) => in_array($order['id'], $orderIds, true)));
            $messageIds = AiIntakeMessage::whereIn('id', array_column($entry['related_mails'] ?? [], 'message_id'))
                ->whereHas('intake', fn ($query) => $query->where(fn ($query) => $query->where('customer_id', $customerId)->orWhereNull('customer_id')))
                ->get()->filter(fn ($message) => (string) ($message->metadata['mailbox_id'] ?? $message->mailbox_id ?? '') === $context['mailbox_id'])->pluck('id')->all();
            $entry['related_mails'] = array_values(array_filter($entry['related_mails'] ?? [], fn ($mail) => in_array($mail['message_id'], $messageIds, true)));
            $entry['permissions']['orders'] = $actor->can('operations.manage');
            $history[] = $entry;
        }

        return ['candidates' => $context['candidates'], 'history' => $history, 'ambiguous' => $context['ambiguous'], 'lookup_truncated' => $context['lookup_truncated']];
    }
}
