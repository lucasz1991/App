<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Facades\Schema;

class EmployeeDocumentRequirement extends Model
{
    public const TYPES = [
        'identity_card' => 'Ausweis',
        'drivers_license' => 'Führerschein',
        'health_insurance_card' => 'Krankenkassenkarte',
        'bank_card' => 'Bankkarte',
        'employment_contract' => 'Arbeitsvertrag',
        'pension_exemption' => 'RV-Befreiungsantrag',
    ];

    protected $fillable = [
        'user_id',
        'document_type',
    ];

    protected static function booted(): void
    {
        static::deleting(function (EmployeeDocumentRequirement $requirement): void {
            abort_if(Schema::hasTable('employee_document_versions') && $requirement->versions()->exists(), 409, 'Dokumentversionen bleiben erhalten.');
            $requirement->file?->delete();
        });
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function file(): MorphOne
    {
        return $this->morphOne(File::class, 'fileable');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(EmployeeDocumentVersion::class)->orderByDesc('revision');
    }
}
