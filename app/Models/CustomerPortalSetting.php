<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerPortalSetting extends Model
{
    public const MODULES = ['orders', 'requests', 'offers', 'changes', 'proofs', 'documents', 'messages', 'reports'];

    protected $guarded = ['id'];

    protected $casts = ['customer_id' => 'integer', 'enabled' => 'boolean', 'revision' => 'integer', 'modules' => 'array', 'notifications' => 'array', 'auto_reject' => 'boolean', 'booking_authority' => 'boolean', 'require_mfa' => 'boolean'];
}
