<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerContact extends Model
{
    public const ROLES = ['ordering' => 'Beauftragung', 'dispatch' => 'Disposition', 'billing' => 'Abrechnung', 'site' => 'Einsatzort', 'acceptance' => 'Abnahme', 'emergency' => 'Notfall'];

    protected $guarded = ['id'];

    protected $casts = ['roles' => 'array', 'revision' => 'integer', 'is_active' => 'boolean'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
