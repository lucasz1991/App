<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    public const STATUSES = [
        'missing' => 'Fehlt',
        'submitted' => 'Eingereicht',
        'verified' => 'Geprüft',
        'not_required' => 'Nicht erforderlich',
    ];

    protected $fillable = [
        'user_id',
        'document_type',
        'status',
        'file_id',
        'verified_by',
        'verified_at',
    ];

    protected $casts = [
        'verified_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }

    public function verifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by');
    }
}
