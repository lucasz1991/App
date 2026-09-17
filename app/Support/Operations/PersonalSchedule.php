<?php

namespace App\Support\Operations;

use App\Models\Order;
use App\Models\Shift;
use App\Models\ShiftAssignment;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/** Read-only employee-facing projection. Never save the returned model instances. */
final class PersonalSchedule
{
    private const PUBLISHED_FIELDS = [
        'order_id', 'title', 'role_name', 'starts_at', 'ends_at',
        'timezone', 'location_name', 'planned_break_minutes',
    ];

    /** @return Collection<int, ShiftAssignment> */
    public function assignments(User $user, ?CarbonImmutable $from = null, ?CarbonImmutable $until = null): Collection
    {
        $this->authorize($user);
        $from = $from?->utc();
        $until = $until?->utc();
        if ($from && $until && $until->lte($from)) {
            return collect();
        }

        // A draft can move a released shift out of the selected interval. Apply
        // interval limits only after resolving the employee-visible snapshot.
        $items = $this->query($user)->get()
            ->map(fn (ShiftAssignment $assignment) => $this->project($assignment))
            ->filter(fn (?ShiftAssignment $assignment) => $assignment !== null
                && (! $from || $assignment->shift->ends_at->utc()->gt($from))
                && (! $until || $assignment->shift->starts_at->utc()->lt($until)))
            ->sortBy(fn (ShiftAssignment $assignment) => $assignment->shift->starts_at->utc()->getTimestamp())
            ->values();

        return $this->loadPublishedOrders($items);
    }

    public function assignment(User $user, int $id): ShiftAssignment
    {
        $this->authorize($user);
        $record = $this->query($user)->whereKey($id)->first();
        $projected = $record ? $this->project($record) : null;
        abort_unless($projected, 404);

        return $this->loadPublishedOrders(collect([$projected]))->first();
    }

    private function authorize(User $user): void
    {
        OperationsAccess::own($user, $user->id);
        // Explicit actors also support trusted server-side callers. Within an
        // authenticated request, passing somebody else is never a scope switch.
        abort_if(auth()->check() && (int) auth()->id() !== (int) $user->id, 403);
        OperationsAccess::requireReady();
    }

    private function query(User $user): Builder
    {
        return ShiftAssignment::query()
            ->select(['id', 'shift_id', 'user_id', 'status', 'plan_revision', 'responded_at'])
            ->where('user_id', $user->id)
            ->whereIn('status', ['requested', 'confirmed'])
            ->whereHas('shift', fn (Builder $query) => $query
                ->where('published_revision', '>', 0)
                ->where('status', '!=', 'cancelled')
                ->whereColumn('shift_assignments.plan_revision', 'shifts.published_revision'))
            ->with([
                'shift' => fn ($query) => $query->select(array_merge(
                    ['id', 'status', 'revision', 'published_revision', 'published_snapshot'],
                    self::PUBLISHED_FIELDS,
                )),
                'timeEntry' => fn ($query) => $query->where('user_id', $user->id),
            ])
            ->orderBy('id');
    }

    private function project(ShiftAssignment $assignment): ?ShiftAssignment
    {
        $shift = $assignment->shift;
        if (! $shift || $shift->getRawOriginal('status') === 'cancelled'
            || $assignment->plan_revision !== $shift->published_revision
            || $shift->published_revision < 1 || $shift->revision < $shift->published_revision) {
            return null;
        }
        $stale = $shift->published_revision !== $shift->revision;
        try {
            $values = $stale ? $shift->published_snapshot : $shift->only(self::PUBLISHED_FIELDS);
            $values = $this->validatedValues($values);
        } catch (\Throwable) {
            return null;
        }
        if ($values === null) {
            return null;
        }

        // Do not leave current draft attributes (notes, staffing, order, etc.)
        // on the model where a later view could accidentally expose them.
        $published = new Shift;
        $published->setRawAttributes([
            'id' => $shift->id,
            'revision' => $shift->revision,
            'published_revision' => $shift->published_revision,
        ]);
        $published->forceFill($values);
        // Zoned attribute setters cache Carbon objects. Clear that cache so
        // subsequent reads use the published schedule's timezone, not UTC.
        $published->setRawAttributes($published->getAttributes(), true);
        $published->exists = true;
        $published->syncOriginal();
        $published->setRelation('order', null);

        $result = clone $assignment;
        $result->setRelation('shift', $published);
        $result->setAttribute('plan_is_stale', $stale);

        return $result;
    }

    private function validatedValues(mixed $values): ?array
    {
        if (! is_array($values) || array_diff(self::PUBLISHED_FIELDS, array_keys($values))) {
            return null;
        }
        $values = array_intersect_key($values, array_flip(self::PUBLISHED_FIELDS));
        if (! $this->integer($values['order_id']) || (int) $values['order_id'] < 1
            || ! is_string($values['title']) || trim($values['title']) === '' || mb_strlen($values['title']) > 180
            || ! $this->nullableString($values['role_name'], 160)
            || ! $this->nullableString($values['location_name'], 180)
            || ! is_string($values['timezone'])
            || ! in_array($values['timezone'], DateTimeZone::listIdentifiers(DateTimeZone::ALL_WITH_BC), true)
            || ! $this->integer($values['planned_break_minutes'])
            || (int) $values['planned_break_minutes'] < 0 || (int) $values['planned_break_minutes'] > 1439) {
            return null;
        }

        $start = $this->timestamp($values['starts_at']);
        $end = $this->timestamp($values['ends_at']);
        if (! $start || ! $end || $end->lte($start)) {
            return null;
        }
        $values['order_id'] = (int) $values['order_id'];
        $values['planned_break_minutes'] = (int) $values['planned_break_minutes'];
        $values['starts_at'] = $start;
        $values['ends_at'] = $end;

        return $values;
    }

    private function timestamp(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof CarbonInterface) {
            return CarbonImmutable::instance($value)->utc();
        }
        // Published JSON dates carry an explicit offset. Never infer a zone or
        // accept relative text/overflowing dates from a malformed snapshot.
        if (! is_string($value) || ! preg_match('/\A\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,6})?(?:Z|[+-]\d{2}:\d{2})\z/', $value)) {
            return null;
        }
        try {
            $date = new DateTimeImmutable($value);
            $errors = DateTimeImmutable::getLastErrors();
            if ($errors && ($errors['warning_count'] || $errors['error_count'])) {
                return null;
            }

            return CarbonImmutable::instance($date)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function integer(mixed $value): bool
    {
        return is_int($value) || (is_string($value) && ctype_digit($value));
    }

    private function nullableString(mixed $value, int $max): bool
    {
        return $value === null || (is_string($value) && mb_strlen($value) <= $max);
    }

    /** @param Collection<int, ShiftAssignment> $items */
    private function loadPublishedOrders(Collection $items): Collection
    {
        $orders = Order::query()
            ->whereKey($items->map(fn (ShiftAssignment $item) => $item->shift->order_id)->unique())
            ->with('customer:id,company_name')
            ->get(['id', 'order_number', 'customer_id'])
            ->keyBy('id');

        return $items->each(fn (ShiftAssignment $item) => $item->shift->setRelation('order', $orders->get($item->shift->order_id)));
    }
}
