<?php

namespace App\Models;

use App\Models\Concerns\HasZonedSchedule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PersonnelTraining extends Model
{
    use HasZonedSchedule;

    protected $guarded = ['id'];

    protected $casts = ['capacity' => 'integer', 'revision' => 'integer'];

    public function participants(): HasMany
    {
        return $this->hasMany(PersonnelTrainingParticipant::class);
    }
}
