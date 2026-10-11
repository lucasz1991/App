<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DispositionMailboxReview extends Model
{
    public const LABELS = ['unreviewed' => 'Ungeprüft', 'inquiry' => 'Anfrage', 'not_inquiry' => 'Keine Anfrage'];

    protected $guarded = ['id'];

    protected $casts = ['uid' => 'integer', 'uid_validity' => 'integer', 'intake_id' => 'integer', 'revision' => 'integer', 'reviewed_by' => 'integer'];
}
