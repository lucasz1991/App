<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerCondition extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'unit_price_cents' => 'integer', 'valid_from' => 'immutable_date', 'valid_until' => 'immutable_date'];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
