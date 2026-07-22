<?php

namespace App\Models;

use App\Casts\EncryptedBoolean;
use App\Casts\EncryptedDate;
use App\Casts\EncryptedDecimal;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserProfile extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'first_name',
        'last_name',
        'phone',
        'mobile',
        'street',
        'postal_code',
        'city',
        'country',
        'birth_date',
        'birth_place',
        'birth_name',
        'nationality',
        'education',
        'personnel_nr',
        'position',
        'entry_date',
        'multiple_employment',
        'employment_type',
        'weekly_working_hours',
        'additional_information',
        'tax_identification_number',
        'social_security_number',
        'iban',
        'health_insurance',
        'tax_class',
        'children_count',
        'religion',
        'compensation_type',
        'compensation_amount',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'first_name' => 'encrypted',
        'last_name' => 'encrypted',
        'phone' => 'encrypted',
        'mobile' => 'encrypted',
        'street' => 'encrypted',
        'postal_code' => 'encrypted',
        'city' => 'encrypted',
        'country' => 'encrypted',
        'birth_date' => EncryptedDate::class,
        'birth_place' => 'encrypted',
        'birth_name' => 'encrypted',
        'nationality' => 'encrypted',
        'education' => 'encrypted',
        'personnel_nr' => 'encrypted',
        'position' => 'encrypted',
        'entry_date' => EncryptedDate::class,
        'multiple_employment' => EncryptedBoolean::class,
        'employment_type' => 'encrypted',
        'weekly_working_hours' => EncryptedDecimal::class.':2',
        'additional_information' => 'encrypted',
        'tax_identification_number' => 'encrypted',
        'social_security_number' => 'encrypted',
        'iban' => 'encrypted',
        'health_insurance' => 'encrypted',
        'tax_class' => 'encrypted',
        'children_count' => EncryptedDecimal::class.':0',
        'religion' => 'encrypted',
        'compensation_type' => 'encrypted',
        'compensation_amount' => EncryptedDecimal::class.':2',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
