<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiIntakeProposal extends Model
{
    protected $guarded = ['id'];
    protected $hidden = ['payload'];
    protected $casts = ['intake_id' => 'integer', 'inquiry_id' => 'integer', 'approved_by' => 'integer', 'demand_id' => 'integer', 'revision' => 'integer', 'source_revision' => 'integer', 'inquiry_revision' => 'integer', 'position_index' => 'integer', 'payload' => 'encrypted:array', 'applied_shift_ids' => 'array', 'approved_at' => 'immutable_datetime', 'applied_at' => 'immutable_datetime'];

    public function intake() { return $this->belongsTo(AiIntake::class, 'intake_id'); }
    public function inquiry() { return $this->belongsTo(OperationInquiry::class, 'inquiry_id'); }
    public function demand() { return $this->belongsTo(OrderDemand::class, 'demand_id'); }
    public function approver() { return $this->belongsTo(User::class, 'approved_by'); }
}
