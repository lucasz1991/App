<?php

namespace App\Support\Operations;

use App\Models\Order;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Small read projection; callers retain their existing authorization and write validation. */
final class OrderSelection
{
    public const LIMIT = 25;

    public static function options(string $search = '', array $selected = [], ?int $customerId = null, bool $includeCancelled = false): Collection
    {
        $ids = collect($selected)->filter(fn ($id) => is_scalar($id) && ctype_digit((string) $id) && (int) $id > 0)->map(fn ($id) => (int) $id)->unique()->take(2)->all();
        $query = Order::query()->when($customerId !== null, fn (Builder $q) => $q->where('customer_id', $customerId))
            ->when(! $includeCancelled, fn (Builder $q) => $q->where(fn (Builder $status) => $status->where('status', '!=', 'cancelled')->orWhereIn('id', $ids)));
        $term = trim(mb_substr($search, 0, 100));
        $options = (clone $query)->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $text) => $text
            ->where('order_number', 'like', '%'.$term.'%')->orWhere('title', 'like', '%'.$term.'%')))
            ->orderByDesc('starts_at')->orderByDesc('id')->limit(self::LIMIT)->get(['id', 'customer_id', 'order_number', 'title']);

        // An existing selection must remain labelled even outside this search window.
        return $options->merge($ids ? (clone $query)->whereKey($ids)->get(['id', 'customer_id', 'order_number', 'title']) : collect())->unique('id')->values();
    }
}
