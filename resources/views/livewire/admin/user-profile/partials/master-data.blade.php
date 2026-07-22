@php $company = \App\Support\CompanyData::all(); @endphp
<div class="grid gap-4 lg:grid-cols-2">
    <section class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
            <i class="far fa-user-circle text-sky-500"></i>{{ __('app.personal_master_data') }}
        </h3>
        <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
            @foreach ([
                __('app.first_name') => $profile?->first_name,
                __('app.last_name') => $profile?->last_name,
                __('app.birth_date') => $profile?->birth_date?->format('d.m.Y'),
                __('app.birth_place') => $profile?->birth_place,
                __('app.birth_name') => $profile?->birth_name,
                __('app.nationality') => $profile?->nationality,
                __('app.school_education') => $profile?->education,
            ] as $label => $value)
                <div class="flex items-start justify-between gap-4 py-2.5">
                    <dt class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt>
                    <dd class="max-w-[60%] text-right text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $value ?: '–' }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60">
        <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
            <i class="far fa-briefcase text-sky-500"></i>{{ __('app.employment_data') }}
        </h3>
        <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
            @foreach ([
                __('app.company') => ($company['name'] ?? '–'),
                __('app.form_date') => $profile?->updated_at?->format('d.m.Y'),
                __('app.personnel_nr') => $profile?->personnel_nr,
                __('app.position') => $profile?->position,
                __('app.entry_date') => $profile?->entry_date?->format('d.m.Y'),
                __('app.multiple_employment') => is_null($profile?->multiple_employment) ? null : ($profile->multiple_employment ? __('app.yes') : __('app.no')),
                __('app.employment_type') => $profile?->employment_type,
                __('app.weekly_working_hours') => $profile?->weekly_working_hours,
            ] as $label => $value)
                <div class="flex items-start justify-between gap-4 py-2.5">
                    <dt class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ $label }}</dt>
                    <dd class="max-w-[60%] text-right text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $value ?: '–' }}</dd>
                </div>
            @endforeach
            <div class="py-2.5">
                <dt class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.additional_information') }}</dt>
                <dd class="mt-2 whitespace-pre-wrap text-sm text-rt-text dark:text-rt-dark-text">{{ $profile?->additional_information ?: '–' }}</dd>
            </div>
        </dl>
    </section>
</div>
