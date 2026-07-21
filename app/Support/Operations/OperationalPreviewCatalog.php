<?php

namespace App\Support\Operations;

final class OperationalPreviewCatalog
{
    /**
     * These modules deliberately contain static preview data only. They are
     * kept outside Eloquent so the future operational schema remains open.
     *
     * @return list<string>
     */
    public static function slugs(): array
    {
        return ['orders', 'shift-management', 'calendar', 'customers'];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function all(): array
    {
        return [
            'orders' => [
                'slug' => 'orders',
                'title' => __('app.operational_orders'),
                'description' => __('app.operational_orders_description'),
                'icon' => 'clipboard',
                'tone' => 'red',
                'metric' => '12',
                'metric_label' => __('app.preview_open_orders'),
                'badge' => __('app.preview_due_today', ['count' => 4]),
                'stats' => [
                    ['label' => __('app.preview_open'), 'value' => '12', 'detail' => __('app.preview_prioritized', ['count' => 3])],
                    ['label' => __('app.preview_in_progress'), 'value' => '7', 'detail' => __('app.preview_teams_on_route', ['count' => 2])],
                    ['label' => __('app.preview_completed_today'), 'value' => '9', 'detail' => __('app.preview_plan_status')],
                ],
                'list_title' => __('app.preview_current_orders'),
                'items' => [
                    ['eyebrow' => 'RT-2407', 'title' => 'Hamburg Hbf → Bremen Hbf', 'meta' => '08:45–11:20 · Team Nord', 'status' => __('app.preview_on_route')],
                    ['eyebrow' => 'RT-2411', 'title' => 'Hannover Hbf → Berlin Hbf', 'meta' => '11:10–14:05 · Team Ost', 'status' => __('app.preview_prepared')],
                    ['eyebrow' => 'RT-2415', 'title' => 'Kiel Hbf → Hamburg Hbf', 'meta' => '14:30–16:00 · Team Nord', 'status' => __('app.preview_planned')],
                ],
            ],
            'shift-management' => [
                'slug' => 'shift-management',
                'title' => __('app.shift_management'),
                'description' => __('app.shift_management_description'),
                'icon' => 'clock',
                'tone' => 'amber',
                'metric' => '3',
                'metric_label' => __('app.preview_active_shifts'),
                'badge' => __('app.preview_staff_on_duty', ['count' => 18]),
                'stats' => [
                    ['label' => __('app.preview_early_shift'), 'value' => '8', 'detail' => __('app.preview_fully_staffed')],
                    ['label' => __('app.preview_late_shift'), 'value' => '7', 'detail' => __('app.preview_replacement_open', ['count' => 1])],
                    ['label' => __('app.preview_night_shift'), 'value' => '3', 'detail' => __('app.preview_fully_staffed')],
                ],
                'list_title' => __('app.preview_shift_overview'),
                'items' => [
                    ['eyebrow' => __('app.preview_now'), 'title' => __('app.preview_early_shift_north'), 'meta' => '06:00–14:00 · 8 Personen', 'status' => __('app.preview_active')],
                    ['eyebrow' => '14:00', 'title' => __('app.preview_late_shift_operations'), 'meta' => '14:00–22:00 · 7 Personen', 'status' => __('app.preview_handover')],
                    ['eyebrow' => '22:00', 'title' => __('app.preview_night_shift_dispatch'), 'meta' => '22:00–06:00 · 3 Personen', 'status' => __('app.preview_planned')],
                ],
            ],
            'calendar' => [
                'slug' => 'calendar',
                'title' => __('app.operational_calendar'),
                'description' => __('app.operational_calendar_description'),
                'icon' => 'calendar',
                'tone' => 'blue',
                'metric' => '8',
                'metric_label' => __('app.preview_appointments_today'),
                'badge' => __('app.preview_next_in_minutes', ['count' => 35]),
                'stats' => [
                    ['label' => __('app.preview_today'), 'value' => '8', 'detail' => __('app.preview_two_locations')],
                    ['label' => __('app.preview_this_week'), 'value' => '27', 'detail' => __('app.preview_three_deadlines')],
                    ['label' => __('app.preview_open_conflicts'), 'value' => '2', 'detail' => __('app.preview_check_required')],
                ],
                'list_title' => __('app.preview_next_appointments'),
                'items' => [
                    ['eyebrow' => '09:30', 'title' => __('app.preview_shift_handover'), 'meta' => 'Leitstelle · Besprechungsraum 1', 'status' => __('app.preview_in_minutes', ['count' => 35])],
                    ['eyebrow' => '11:00', 'title' => __('app.preview_customer_coordination'), 'meta' => 'NordCargo GmbH · Videokonferenz', 'status' => __('app.preview_confirmed')],
                    ['eyebrow' => '15:30', 'title' => __('app.preview_weekly_planning'), 'meta' => 'Disposition · Besprechungsraum 2', 'status' => __('app.preview_planned')],
                ],
            ],
            'customers' => [
                'slug' => 'customers',
                'title' => __('app.customer_database'),
                'description' => __('app.customer_database_description'),
                'icon' => 'briefcase',
                'tone' => 'emerald',
                'metric' => '248',
                'metric_label' => __('app.preview_customer_records'),
                'badge' => __('app.preview_new_this_month', ['count' => 6]),
                'stats' => [
                    ['label' => __('app.preview_active_customers'), 'value' => '214', 'detail' => __('app.preview_demo_portfolio')],
                    ['label' => __('app.preview_open_requests'), 'value' => '11', 'detail' => __('app.preview_due_today', ['count' => 4])],
                    ['label' => __('app.preview_contacts'), 'value' => '391', 'detail' => __('app.preview_centralized')],
                ],
                'list_title' => __('app.preview_recent_customers'),
                'items' => [
                    ['eyebrow' => 'K-1048', 'title' => 'NordCargo GmbH', 'meta' => 'Hamburg · 4 Ansprechpartner', 'status' => __('app.preview_active')],
                    ['eyebrow' => 'K-1182', 'title' => 'HanseRail Services', 'meta' => 'Bremen · 2 Ansprechpartner', 'status' => __('app.preview_request_open')],
                    ['eyebrow' => 'K-1231', 'title' => 'Elbe Logistik AG', 'meta' => 'Magdeburg · 5 Ansprechpartner', 'status' => __('app.preview_active')],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(string $slug): ?array
    {
        return $this->all()[$slug] ?? null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function dashboard(): array
    {
        return array_values(array_map(
            static fn (array $module): array => array_intersect_key($module, array_flip([
                'slug', 'title', 'description', 'icon', 'tone', 'metric', 'metric_label', 'badge',
            ])),
            $this->all(),
        ));
    }
}
