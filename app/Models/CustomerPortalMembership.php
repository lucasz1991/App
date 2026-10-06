<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerPortalMembership extends Model
{
    public const ROLES = [
        'reader' => ['orders.view', 'documents.view'],
        'requester' => ['orders.view', 'documents.view', 'requests.create', 'messages.create'],
        'orderer' => ['orders.view', 'documents.view', 'requests.create', 'offers.accept', 'changes.create', 'messages.create'],
        'proof_reviewer' => ['orders.view', 'documents.view', 'proofs.accept', 'messages.create'],
        'coordinator' => ['orders.view', 'documents.view', 'requests.create', 'offers.accept', 'changes.create', 'proofs.accept', 'messages.create', 'reports.view'],
    ];

    protected $guarded = ['id'];

    protected $casts = ['customer_id' => 'integer', 'identity_id' => 'integer', 'contact_id' => 'integer', 'revision' => 'integer', 'capabilities' => 'array', 'location_ids' => 'array', 'history_from' => 'immutable_date', 'activated_at' => 'immutable_datetime', 'revoked_at' => 'immutable_datetime'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(CustomerContact::class, 'contact_id');
    }

    public function identity(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalIdentity::class, 'identity_id');
    }

    public function setting(): BelongsTo
    {
        return $this->belongsTo(CustomerPortalSetting::class, 'customer_id', 'customer_id');
    }
}
