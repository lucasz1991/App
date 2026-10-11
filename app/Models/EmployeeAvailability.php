<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use App\Services\Dropbox\NativeAvailabilityWorkbook;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

class EmployeeAvailability extends Model
{
    use CapturesDropboxChanges;

    protected $guarded = ['id'];

    protected $casts = ['from' => 'immutable_date', 'until' => 'immutable_date', 'weekdays' => 'array', 'whole_day' => 'boolean', 'revision' => 'integer'];

    protected static function booted(): void
    {
        static::deleting(function (self $wish) {
            if (Schema::hasTable('dropbox_records')) {
                app(NativeAvailabilityWorkbook::class)->captureWithdrawal($wish);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
