<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WorkforcePool extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean', 'revision' => 'integer'];

    public function users()
    {
        return $this->belongsToMany(User::class, 'workforce_pool_user');
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_id');
    }
}
