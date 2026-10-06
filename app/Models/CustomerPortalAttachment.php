<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class CustomerPortalAttachment extends Model
{
    use HasUtcInstants;

    protected $guarded = ['id'];

    protected $casts = ['review_note' => 'encrypted', 'revision' => 'integer', 'file_size' => 'integer', 'reviewed_at' => 'immutable_datetime'];

    protected function reviewedAt(): Attribute
    {
        return $this->utcInstant();
    }
}
