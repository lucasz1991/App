<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftOfferResponse extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['responded_at' => 'immutable_datetime', 'revision' => 'integer'];

    public function offer()
    {
        return $this->belongsTo(ShiftOffer::class, 'shift_offer_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
