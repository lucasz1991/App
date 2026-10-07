<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class AiIntakeProposal extends Model
{
    use HasUtcInstants;

    protected function approvedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function appliedAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected $guarded = ['id'];

    protected $hidden = ['payload'];

    protected $casts = ['intake_id' => 'integer', 'inquiry_id' => 'integer', 'approved_by' => 'integer', 'demand_id' => 'integer', 'demand_ids' => 'array', 'revision' => 'integer', 'source_revision' => 'integer', 'inquiry_revision' => 'integer', 'position_index' => 'integer', 'payload' => 'encrypted:array', 'applied_shift_ids' => 'array', 'approved_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];

    public function intake()
    {
        return $this->belongsTo(AiIntake::class, 'intake_id');
    }

    public function inquiry()
    {
        return $this->belongsTo(OperationInquiry::class, 'inquiry_id');
    }

    public function demand()
    {
        return $this->belongsTo(OrderDemand::class, 'demand_id');
    }

    public function approver()
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
