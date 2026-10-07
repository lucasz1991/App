<?php

namespace App\Support\CustomerPortal;

use App\Models\Customer;
use App\Models\CustomerPortalIdentity;
use App\Models\CustomerPortalMembership;
use App\Models\OperationInquiry;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsAutomationActor;
use Illuminate\Support\Facades\Schema;

final class PortalActor
{
    public static function internalId(User|CustomerPortalIdentity|OperationsAutomationActor $actor): ?int
    {
        return $actor instanceof User ? (int) $actor->id : null;
    }

    public static function scope(CustomerPortalIdentity $identity, int $customerId, string $ability): CustomerPortalMembership
    {
        abort_unless(Schema::hasColumn('operation_inquiries', 'customer_portal_identity_id'), 503);
        Customer::whereKey($customerId)->lockForUpdate()->firstOrFail();

        return app(CustomerPortalScope::class)->membership($identity, $customerId, $ability, true);
    }

    public static function references(User|CustomerPortalIdentity|OperationsAutomationActor $actor, int $customerId): array
    {
        if ($actor instanceof User || $actor instanceof OperationsAutomationActor) {
            return [];
        }
        $membership = app(CustomerPortalScope::class)->membership($actor, $customerId);

        return ['customer_portal_identity_id' => $actor->id, 'customer_portal_membership_id' => $membership->id];
    }

    public static function authorizeInquiry(User|CustomerPortalIdentity|OperationsAutomationActor $actor, ?OperationInquiry $inquiry, array $input, string $action = 'save'): void
    {
        if ($actor instanceof OperationsAutomationActor) {
            $actor->authorize('inquiry.'.$action, 'operations.inquiries.manage');
            abort_unless(in_array($action, ['save', 'verify'], true), 403);
            $actor->assertInquiryScope($inquiry, $input);

            return;
        }
        if ($actor instanceof User) {
            OperationsAccess::authorize($actor, 'operations.inquiries.manage');

            return;
        }
        $customerId = (int) ($inquiry?->customer_id ?? $input['customer_id'] ?? 0);
        self::scope($actor, $customerId, $action === 'accept' ? 'offers.accept' : 'requests.create');
        abort_unless(in_array($action, ['save', 'verify', 'accept'], true), 403);
        if ($action === 'save') {
            abort_if($inquiry !== null || ($input['channel'] ?? '') !== 'portal', 403);
        } elseif ($inquiry) {
            app(CustomerPortalScope::class)->inquiries($actor, $customerId, null)->whereKey($inquiry->id)->firstOrFail();
        }
    }
}
