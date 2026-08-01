<?php

namespace App\Models;

use App\Enums\OrderPriority;
use App\Enums\OrderStatus;
use App\Models\Concerns\HasZonedSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class Order extends Model
{
    use HasFactory;
    use HasZonedSchedule;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'order_number',
        'customer_id',
        'title',
        'service_type',
        'description',
        'status',
        'priority',
        'starts_at',
        'ends_at',
        'timezone',
        'location_name',
        'street',
        'postal_code',
        'city',
        'country',
        'required_staff',
        'requirements',
        'notes',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => OrderStatus::class,
        'priority' => OrderPriority::class,
        'required_staff' => 'integer',
        'requirements' => 'array',
    ];

    protected static function booted(): void
    {
        static::creating(function (self $order): void {
            if (blank($order->public_id)) {
                $order->public_id = (string) Str::uuid();
            }
        });

        static::created(function (self $order): void {
            if (blank($order->order_number)) {
                $startsAt = $order->starts_at?->copy()->setTimezone($order->timezone);
                $year = (int) ($startsAt?->format('Y') ?? now($order->timezone)->format('Y'));

                $order->forceFill([
                    'order_number' => sprintf('RT-%04d-%06d', $year, $order->getKey()),
                ])->saveQuietly();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(OrderStatusHistory::class)->latest();
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class)->orderBy('starts_at');
    }

    public function files(): MorphMany
    {
        return $this->morphMany(File::class, 'fileable');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopeWithStatus(Builder $query, OrderStatus|string $status): Builder
    {
        return $query->where('status', $status instanceof OrderStatus ? $status->value : $status);
    }

    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where('ends_at', '>=', now()->utc());
    }

    public function scopeDuring(Builder $query, mixed $startsAt, mixed $endsAt): Builder
    {
        return $query
            ->where('starts_at', '<', CarbonImmutable::parse($endsAt)->utc())
            ->where('ends_at', '>', CarbonImmutable::parse($startsAt)->utc());
    }

    public function scopeSearch(Builder $query, string $term): Builder
    {
        $term = '%'.trim($term).'%';

        return $query->where(function (Builder $search) use ($term): void {
            $search
                ->where('order_number', 'like', $term)
                ->orWhere('title', 'like', $term)
                ->orWhere('service_type', 'like', $term)
                ->orWhereHas('customer', function (Builder $customer) use ($term): void {
                    $customer
                        ->where('company_name', 'like', $term)
                        ->orWhere('customer_number', 'like', $term);
                });
        });
    }
}
