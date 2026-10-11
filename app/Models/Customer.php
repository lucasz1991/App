<?php

namespace App\Models;

use App\Models\Concerns\CapturesDropboxChanges;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Customer extends Model
{
    use CapturesDropboxChanges;
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

    public function getCompanyInitialsAttribute(): string
    {
        $parts = preg_split('/[\s\-\/]+/u', trim((string) $this->company_name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (count($parts) === 1) {
            return mb_strtoupper(mb_substr($parts[0], 0, 2));
        }

        return mb_strtoupper(implode('', array_map(fn (string $part) => mb_substr($part, 0, 1), array_slice($parts, 0, 2)))) ?: '?';
    }

    public function hasValidLogoPath(?string $path = null): bool
    {
        $path ??= $this->logo_path;

        return is_string($path) && preg_match('#^customer-logos/'.(int) $this->id.'/[a-f0-9]{8}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{4}-[a-f0-9]{12}\.png$#D', $path) === 1;
    }

    public function getLogoUrlAttribute(): ?string
    {
        return $this->logoUrl();
    }

    public function logoUrl(bool $portal = false): ?string
    {
        if (! $this->hasValidLogoPath()) {
            return null;
        }

        return route($portal ? 'customer-portal.logo' : 'operations.customers.logo', [
            'customer' => $this->id,
            'v' => substr(hash('sha256', $this->logo_path), 0, 12),
        ], false);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(CustomerContact::class);
    }

    public function conditions(): HasMany
    {
        return $this->hasMany(CustomerCondition::class)->orderByDesc('valid_from');
    }

    public function locations(): HasMany
    {
        return $this->hasMany(CustomerLocation::class);
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
