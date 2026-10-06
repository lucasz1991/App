<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationsMonthClosing extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['snapshot' => 'encrypted:array', 'revision' => 'integer', 'closed_at' => 'immutable_datetime'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function revisions()
    {
        return $this->hasMany(OperationsClosingRevision::class);
    }
}
