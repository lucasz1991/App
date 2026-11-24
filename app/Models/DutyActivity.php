<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class DutyActivity extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected $casts = ['active' => 'boolean', 'requirements_confirmed' => 'boolean', 'wagon_list' => 'boolean', 'individual_proof' => 'boolean', 'customer_acceptance' => 'boolean', 'revision' => 'integer'];

    public function bundle(): BelongsTo
    {
        return $this->belongsTo(QualificationBundle::class, 'qualification_bundle_id');
    }

    public static function fromCode(?string $code): ?self
    {
        if (blank($code) || ! Schema::hasTable('duty_activities')) {
            return null;
        }

        $code = preg_replace('~\s*/\s*~u', '/', trim($code));

        return static::whereIn('id', DB::table('duty_activity_codes')
            ->where('lookup', mb_strtolower($code))->select('duty_activity_id'))->first();
    }

    public function snapshot(): array
    {
        $bundle = $this->bundle;
        $approved = ! $this->qualification_bundle_id || $bundle?->status === 'approved';

        return [
            'activity_id' => $this->id, 'code' => $this->code, 'title' => $this->title ?: $this->code,
            'confirmed' => $this->active && $this->requirements_confirmed && $approved,
            'bundle_id' => $bundle?->id, 'bundle_version' => $bundle?->version,
            'requirements' => $bundle?->requirements ?? [], 'proof_type' => $this->proof_type,
            'wagon_list' => $this->wagon_list, 'individual_proof' => $this->individual_proof, 'customer_acceptance' => $this->customer_acceptance,
        ];
    }
}
