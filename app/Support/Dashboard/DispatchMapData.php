<?php

namespace App\Support\Dashboard;

use App\Models\OperationInquiry;
use App\Models\Shift;
use App\Models\User;
use App\Support\Operations\OperationsAccess;
use App\Support\Operations\OperationsPages;
use App\Support\Operations\TimelineLocationPreview;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/** Read-only native disposition map; coordinates never change stored locations. */
final class DispatchMapData
{
    public const ITEMS_PER_TYPE = 100;

    public const MARKER_ITEMS_PER_TYPE = 3;

    public static function availableFor(User $user): bool
    {
        return $user->status
            && in_array($user->dashboardAudience(), ['admin', 'administration', 'management'], true)
            && ($user->can('operations.manage') || $user->can('operations.inquiries.manage'))
            && OperationsAccess::ready();
    }

    public static function timezone(): string
    {
        return (string) config('operations.display_timezone', 'Europe/Berlin');
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::timezone())->toDateString();
    }

    /** Strict civil date; overflow such as February 30 must preserve selection. */
    public static function day(string $date): CarbonImmutable
    {
        $value = preg_match('/^(?!0000)\d{4}-\d{2}-\d{2}$/D', $date)
            ? DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone(self::timezone()))
            : false;
        if (! $value || $value->format('Y-m-d') !== $date || $date < '1900-01-01' || $date > '2100-12-31') {
            throw ValidationException::withMessages(['dispatchMapDate' => 'Bitte ein gültiges Datum auswählen.']);
        }

        return CarbonImmutable::instance($value);
    }

    public function forUser(User $user, ?string $date = null): array
    {
        if (! self::availableFor($user)) {
            return [];
        }

        $day = self::day($date ?? self::today());
        // A civil next day produces the correct 23/25-hour UTC interval at DST.
        $start = $day->utc();
        $end = $day->addDay()->utc();
        $canViewShifts = $user->can('operations.manage');
        $canViewInquiries = $user->can('operations.inquiries.manage');
        $totals = ['shifts' => 0, 'inquiries' => 0, 'undated' => 0, 'located' => 0, 'unlocated' => 0];
        $markers = [];
        $items = [];
        $displayed = ['shifts' => 0, 'inquiries' => 0];

        if ($canViewShifts) {
            $query = Shift::query()->notCancelled()->during($start, $end)
                ->select(['id', 'public_id', 'order_id', 'title', 'role_name', 'starts_at', 'ends_at', 'timezone', 'location_name', 'required_staff', 'status'])
                ->with(['order:id,customer_id,title,location_name,city,postal_code,country', 'order.customer:id,company_name'])
                ->withCount(['assignments as assigned_staff' => fn (Builder $q) => $q->where('status', 'confirmed')]);

            foreach ($query->lazyById(250) as $shift) {
                $item = $this->shiftItem($shift, $day->toDateString());
                $this->aggregate($item, 'shifts', $totals, $markers, $items, $displayed);
            }
        }

        if ($canViewInquiries) {
            $query = OperationInquiry::query()->whereNull('order_id')->whereNull('duplicate_of_id')
                ->whereIn('status', ['new', 'verified', 'offered', 'accepted'])
                ->where(function (Builder $q) use ($start, $end): void {
                    $q->where(fn (Builder $q) => $q->where('starts_at', '<', $end)->where('ends_at', '>', $start))
                        ->orWhere(fn (Builder $q) => $q->whereNull('ends_at')->where('starts_at', '>=', $start)->where('starts_at', '<', $end))
                        ->orWhere(fn (Builder $q) => $q->whereNull('starts_at')->where('ends_at', '>=', $start)->where('ends_at', '<', $end))
                        ->orWhere(fn (Builder $q) => $q->whereNull('starts_at')->whereNull('ends_at'));
                })
                ->select(['id', 'customer_id', 'title', 'status', 'starts_at', 'ends_at', 'timezone', 'location_name', 'role_name', 'required_staff'])
                ->with('customer:id,company_name');

            foreach ($query->lazyById(250) as $inquiry) {
                $item = $this->inquiryItem($inquiry);
                $this->aggregate($item, 'inquiries', $totals, $markers, $items, $displayed);
            }
        }

        usort($items, fn (array $a, array $b) => [$a['undated'], $a['sortTime'], $a['key']] <=> [$b['undated'], $b['sortTime'], $b['key']]);
        usort($markers, fn (array $a, array $b) => strnatcasecmp($a['place'], $b['place']));
        $count = $totals['shifts'] + $totals['inquiries'];

        return [
            'date' => $day->toDateString(), 'dateLabel' => $day->format('d.m.Y'), 'today' => self::today(), 'timezone' => self::timezone(),
            'canViewShifts' => $canViewShifts, 'canViewInquiries' => $canViewInquiries,
            'totals' => $totals, 'shiftsCount' => $totals['shifts'], 'inquiriesCount' => $totals['inquiries'],
            'undatedCount' => $totals['undated'], 'locatedCount' => $totals['located'], 'unlocatedCount' => $totals['unlocated'],
            'items' => $items, 'markers' => array_values($markers),
            'unlocatedItems' => array_values(array_filter($items, fn (array $item) => $item['location']['state'] !== 'located')),
            'count' => $count, 'displayedCount' => count($items), 'displayedByType' => $displayed,
            'truncated' => $count > count($items), 'itemLimit' => self::ITEMS_PER_TYPE * ((int) $canViewShifts + (int) $canViewInquiries),
            'itemsPerType' => self::ITEMS_PER_TYPE,
            'mapPath' => TimelineLocationPreview::outlinePath(), 'viewBox' => TimelineLocationPreview::viewBox(),
            'shiftsHref' => $canViewShifts ? OperationsPages::moduleUrl('shift-management', ['from' => $day->toDateString(), 'until' => $day->toDateString()]) : null,
            'inquiriesHref' => $canViewInquiries ? OperationsPages::moduleUrl('inquiries') : null,
        ];
    }

    private function aggregate(array $item, string $type, array &$totals, array &$markers, array &$items, array &$displayed): void
    {
        $totals[$type]++;
        $totals['undated'] += (int) $item['undated'];
        $located = $item['location']['state'] === 'located';
        $totals[$located ? 'located' : 'unlocated']++;
        $retained = $displayed[$type] < self::ITEMS_PER_TYPE;
        $item['locationKey'] = $located ? $this->locationKey($item['location']) : 'unlocated';

        if ($retained) {
            $items[] = $item;
            $displayed[$type]++;
        }
        if (! $located) {
            return;
        }

        $key = $item['locationKey'];
        $location = $item['location'];
        $markers[$key] ??= [
            'key' => $key, 'place' => $location['place'], 'label' => $location['label'],
            'x' => $location['x'], 'y' => $location['y'], 'latitude' => $location['latitude'], 'longitude' => $location['longitude'],
            'shiftCount' => 0, 'inquiryCount' => 0, 'count' => 0, 'itemKeys' => [],
            'items' => [], 'detailsPerType' => self::MARKER_ITEMS_PER_TYPE, 'detailsTruncated' => false,
        ];
        $typeCount = $type === 'shifts' ? 'shiftCount' : 'inquiryCount';
        $markers[$key][$typeCount]++;
        $markers[$key]['count']++;
        // Every point needs native details, including places beyond the list cap.
        // Keep each type independently bounded so filtering still has a sample.
        if ($markers[$key][$typeCount] <= self::MARKER_ITEMS_PER_TYPE) {
            $markers[$key]['items'][] = $item;
        }
        $markers[$key]['detailsTruncated'] = $markers[$key]['count'] > count($markers[$key]['items']);
        if ($retained) {
            $markers[$key]['itemKeys'][] = $item['key'];
        }
    }

    private function locationKey(array $location): string
    {
        // Canonical WGS84 locality point; names and rounded SVG pixels can collide.
        return number_format($location['latitude'], 6, '.', '').','.number_format($location['longitude'], 6, '.', '');
    }

    private function shiftItem(Shift $shift, string $date): array
    {
        return $this->period($shift->starts_at, $shift->ends_at) + [
            'key' => 'shift-'.$shift->id, 'type' => 'shift', 'id' => $shift->id, 'title' => $shift->title,
            'number' => $shift->order?->title, 'customer' => $shift->order?->customer?->company_name,
            'roleName' => $shift->role_name, 'status' => $shift->status->value, 'statusLabel' => $shift->status->label(),
            'locationLabel' => $shift->location_name ?: ($shift->order?->location_name ?: $shift->order?->city),
            'location' => TimelineLocationPreview::fromShift($shift),
            'href' => OperationsPages::moduleUrl('shift-management', ['shift' => $shift->id, 'from' => $date, 'until' => $date]),
            'requiredStaff' => (int) $shift->required_staff, 'assignedStaff' => (int) $shift->assigned_staff,
        ];
    }

    private function inquiryItem(OperationInquiry $inquiry): array
    {
        return $this->period($inquiry->starts_at, $inquiry->ends_at) + [
            'key' => 'inquiry-'.$inquiry->id, 'type' => 'inquiry', 'id' => $inquiry->id, 'title' => $inquiry->title,
            'number' => $inquiry->number, 'customer' => $inquiry->customer?->company_name,
            'roleName' => $inquiry->role_name, 'status' => $inquiry->status,
            'statusLabel' => match ($inquiry->status) {
                'verified' => 'Bedarf geprüft', 'offered' => 'Angebot erstellt', 'accepted' => 'Angebot angenommen', default => 'Neue Anfrage',
            },
            'locationLabel' => $inquiry->location_name,
            'location' => TimelineLocationPreview::fromInquiry($inquiry),
            'href' => OperationsPages::moduleUrl('inquiries', ['inquiry' => $inquiry->id]),
            'requiredStaff' => $inquiry->required_staff, 'assignedStaff' => null,
        ];
    }

    private function period(?CarbonImmutable $start, ?CarbonImmutable $end): array
    {
        $start = $start?->setTimezone(self::timezone());
        $end = $end?->setTimezone(self::timezone());
        $startLabel = $start?->format('d.m.Y H:i');
        $endLabel = $end?->format('d.m.Y H:i');

        return [
            'startLabel' => $startLabel, 'endLabel' => $endLabel,
            'timeLabel' => match (true) {
                $start !== null && $end !== null => $start->isSameDay($end) ? $start->format('H:i').'–'.$end->format('H:i') : $startLabel.'–'.$endLabel,
                $start !== null => 'Ab '.$startLabel.' · Ende offen',
                $end !== null => 'Bis '.$endLabel.' · Beginn offen',
                default => 'Termin offen',
            },
            'undated' => $start === null && $end === null,
            'incompletePeriod' => $start === null || $end === null,
            'sortTime' => $start?->utc()->format('Y-m-d H:i:s') ?? $end?->utc()->format('Y-m-d H:i:s') ?? '',
        ];
    }
}
