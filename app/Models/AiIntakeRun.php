<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntakeRun extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['input_snapshot', 'result', 'configuration'];
    protected $casts = ['intake_id' => 'integer', 'supervising_user_id' => 'integer', 'source_revision' => 'integer', 'settings_revision' => 'integer', 'input_snapshot' => 'encrypted:array', 'result' => 'encrypted:array', 'configuration' => 'encrypted:array', 'started_at' => 'immutable_datetime', 'finished_at' => 'immutable_datetime'];

    public function intake() { return $this->belongsTo(AiIntake::class, 'intake_id'); }
    public function supervisor() { return $this->belongsTo(User::class, 'supervising_user_id'); }
}
