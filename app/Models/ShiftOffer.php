<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ShiftOffer extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['invited_user_ids' => 'array', 'expires_at' => 'immutable_datetime', 'plan_revision' => 'integer', 'revision' => 'integer'];

    public function shift()
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function responses()
    {
        return $this->hasMany(ShiftOfferResponse::class);
    }
}
