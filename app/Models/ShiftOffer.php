<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class ShiftOffer extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['invited_user_ids' => 'array', 'plan_revision' => 'integer', 'revision' => 'integer'];

    protected function expiresAt(): Attribute
    {
        return $this->utcInstant();
    }

    public function shift()
    {
        return $this->belongsTo(Shift::class)->withTrashed();
    }

    public function responses()
    {
        return $this->hasMany(ShiftOfferResponse::class);
    }
}
