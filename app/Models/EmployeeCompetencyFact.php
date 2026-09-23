<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use Illuminate\Database\Eloquent\Model;

class EmployeeCompetencyFact extends Model
{
    use CapturesDropboxChanges;

    protected $guarded = ['id'];

    protected $casts = ['value' => 'array', 'revision' => 'integer'];
}
