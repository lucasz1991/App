<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AiIntakeRun extends Model
{
    use HasUtcInstants;

    protected function startedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function finishedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected $guarded = ['id'];

    protected $hidden = ['input_snapshot', 'result', 'configuration'];

    protected $casts = ['intake_id' => 'integer', 'supervising_user_id' => 'integer', 'source_revision' => 'integer', 'settings_revision' => 'integer', 'input_snapshot' => 'encrypted:array', 'result' => 'encrypted:array', 'configuration' => 'encrypted:array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];

    public function intake()
    {
        return $this->belongsTo(AiIntake::class, 'intake_id');
    }

    public function supervisor()
    {
        return $this->belongsTo(User::class, 'supervising_user_id');
    }
}
