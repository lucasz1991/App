<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerPortalSubmission extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'decision' => 'encrypted:array', 'revision' => 'integer'];

    public function items(): HasMany
    {
        return $this->hasMany(CustomerPortalSubmissionItem::class, 'submission_id')->orderBy('position');
    }
}
