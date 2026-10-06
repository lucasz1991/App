<?php

namespace App\Models;

use App\Models\Concerns\HasUtcInstants;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class OperationsAttentionItem extends Model
{
    use HasUtcInstants;

    protected $attributes = ['revision' => 1];

    protected $guarded = ['id'];

    protected $casts = ['revision' => 'integer', 'source_revision' => 'integer'];

    protected function dueAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function readAt(): Attribute
    {
        return $this->utcInstant();
    }

    protected function resolvedAt(): Attribute
    {
        return $this->utcInstant();
    }
}
