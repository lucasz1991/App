<?php

namespace App\Services\Operations;

use App\Models\AiIntake;
use App\Models\AiIntakeMessage;
use App\Models\Order;
use App\Models\User;
use App\Support\Operations\AiDispositionSettings;
use App\Support\Operations\OperationsAccess;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Presents server-generated history snapshots only within the current actor and source scope. */
final class AiIntakeContextHints
{
    public function forReview(AiIntake $intake, User $actor): array
    {
        $actor = User::findOrFail($actor->id);
        OperationsAccess::authorize($actor, 'operations.inquiries.manage');
        $intake = AiIntake::findOrFail($intake->id);
        $hints = $intake->analysis['context_hints'] ?? [];
        if (($hints['provenance']['kind'] ?? '') !== 'historical_hint'
            || ($hints['provenance']['intake_id'] ?? null) !== $intake->id
            || ($hints['provenance']['source_revision'] ?? null) !== $intake->source_revision
            || ($hints['provenance']['settings_revision'] ?? null) !== (int) AiDispositionSettings::all(true)['revision']) {
            return [];
        }
        $context = app(AiIntakeCustomerContextService::class)->context($intake, $actor);
        if (! $context['source_available']) {
            return [];
        }
        $allowed = array_column($context['candidates'], 'customer_id');
        $history = [];
        foreach (array_slice($hints['history'] ?? [], 0, 2) as $entry) {
            $customerId = (int) ($entry['customer']['id'] ?? 0);
            if (! in_array($customerId, $allowed, true)
                || ($entry['provenance']['version'] ?? '') !== AiIntakeCustomerContextService::VERSION
                || ($entry['provenance']['source_snapshot']['source_revision'] ?? null) !== $context['source_revision']
                || ($entry['provenance']['source_snapshot']['settings_revision'] ?? null) !== $context['settings_revision']
                || ($entry['provenance']['source_snapshot']['mailbox_id'] ?? null) !== $context['mailbox_id']
                || ($entry['provenance']['source_snapshot']['message_hash'] ?? null) !== $context['source_snapshot']['message_hash']) {
                continue;
            }
            $orderIds = $actor->can('operations.manage')
                ? Order::where('customer_id', $customerId)->whereIn('id', array_column($entry['orders'] ?? [], 'id'))->pluck('id')->all() : [];
            $entry['orders'] = array_values(array_slice(array_filter($entry['orders'] ?? [], fn ($order) => in_array($order['id'], $orderIds, true)), 0, 5));
            $messages = AiIntakeMessage::whereIn('id', array_column($entry['related_mails'] ?? [], 'message_id'))->where('direction', 'inbound')
                ->whereHas('intake', fn ($query) => $query->where('source_type', 'email')->where('status', '!=', 'not_inquiry')
                    ->where(fn ($scope) => $scope->where('customer_id', $customerId)->orWhereNull('customer_id')))
                ->with('intake')->get()->keyBy('id');
            $entry['related_mails'] = array_values(array_slice(array_filter($entry['related_mails'] ?? [], function ($mail) use ($messages, $context): bool {
                $message = $messages->get($mail['message_id']);
                if (! $message || ($mail['intake_id'] ?? null) !== (int) $message->intake_id
                    || ($mail['source_revision'] ?? null) !== $message->intake->source_revision
                    || ($mail['intake_revision'] ?? null) !== $message->intake->revision
                    || ($mail['customer_id'] ?? null) !== $message->intake->customer_id
                    || (string) ($message->metadata['mailbox_id'] ?? $message->mailbox_id ?? '') !== $context['mailbox_id']
                    || ($mail['source_hash'] ?? null) !== hash('sha256', json_encode([$message->body, $message->sender_email, $message->raw_hash, $message->metadata], JSON_THROW_ON_ERROR))) {
                    return false;
                }
                try {
                    app(AiIntakeMessageContent::class)->forMessage($message);

                    return true;
                } catch (HttpException $error) {
                    if (! in_array($error->getStatusCode(), [404, 409], true)) {
                        throw $error;
                    }
                } catch (RuntimeException $error) {
                    if (! in_array($error->getMessage(), ['mailbox_content_invalid', 'mailbox_content_limit'], true)) {
                        throw $error;
                    }
                }

                return false;
            }), 0, 5));
            $entry['permissions']['orders'] = $actor->can('operations.manage');
            $history[] = $entry;
        }
        $freshActor = User::findOrFail($actor->id);
        OperationsAccess::authorize($freshActor, 'operations.inquiries.manage');
        if (! $freshActor->can('operations.manage')) {
            foreach ($history as &$entry) {
                $entry['orders'] = [];
                $entry['permissions']['orders'] = false;
            }
            unset($entry);
        }
        $fresh = $intake->fresh();
        if ($fresh->revision !== $intake->revision || $fresh->source_revision !== $context['source_revision']
            || $fresh->latest_inbound_message_id !== $context['message_id']
            || (int) AiDispositionSettings::all(true)['revision'] !== $context['settings_revision']) {
            return [];
        }

        return ['candidates' => $context['candidates'], 'history' => $history, 'ambiguous' => $context['ambiguous'], 'lookup_truncated' => $context['lookup_truncated']];
    }
}
