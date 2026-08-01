<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use HasFactory;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'customer_number',
        'company_name',
        'contact_name',
        'email',
        'phone',
        'street',
        'postal_code',
        'city',
        'country',
        'notes',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $customer): void {
            if (blank($customer->public_id)) {
                $customer->public_id = (string) Str::uuid();
            }
        });

        static::created(function (self $customer): void {
            if (blank($customer->customer_number)) {
                $customer->forceFill([
                    'customer_number' => sprintf('K-%06d', $customer->getKey()),
                ])->saveQuietly();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.trim($term).'%';

        return $query->where(function (Builder $search) use ($term): void {
            $search
                ->where('customer_number', 'like', $term)
                ->orWhere('company_name', 'like', $term)
                ->orWhere('contact_name', 'like', $term)
                ->orWhere('email', 'like', $term);
        });
    }
}
