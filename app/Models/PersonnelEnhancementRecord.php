<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Private payloads must be explicitly projected by authorized services. */
abstract class PersonnelEnhancementRecord extends Model
{
    protected $attributes = ['revision' => 1];

    protected $guarded = ['id'];

    protected $hidden = ['payload', 'snapshot', 'results'];

    protected $casts = ['payload' => 'encrypted:array', 'snapshot' => 'encrypted:array', 'results' => 'encrypted:array', 'revision' => 'integer', 'approved_at' => 'immutable_datetime', 'confirmed_at' => 'immutable_datetime', 'decided_at' => 'immutable_datetime', 'due_at' => 'immutable_datetime', 'escalated_at' => 'immutable_datetime'];
}
