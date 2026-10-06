<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class OperationWorkflow extends Model
{
    protected $attributes = ['revision' => 1, 'status' => 'draft'];

    protected $guarded = ['id'];

    protected $casts = ['payload' => 'encrypted:array', 'revision' => 'integer'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function order()
    {
        return $this->belongsTo(Order::class);
    }

    public function revisions()
    {
        return $this->hasMany(OperationWorkflowRevision::class);
    }
}
