@php
    $company = \App\Support\CompanyData::all();
    $employmentTypeOptions = [
        '' => __('app.please_select'),
        'employee' => __('app.salaried_employee'),
        'worker' => __('app.worker'),
    ];
    $multipleEmploymentOptions = [
        '' => __('app.please_select'),
        '0' => __('app.no'),
        '1' => __('app.yes'),
    ];
@endphp

<div class="grid gap-4 lg:grid-cols-2" data-anim-stagger>
    <section class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-rt-glow>
        <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                <i class="far fa-user-circle text-sm"></i>
            </span>
            {{ __('app.personal_master_data') }}
        </h3>

        <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
            @foreach ([
                ['first_name', 'first_name', 'text', $profile?->first_name],
                ['last_name', 'last_name', 'text', $profile?->last_name],
                ['birth_date', 'birth_date', 'date', $profile?->birth_date?->format('d.m.Y')],
                ['birth_place', 'birth_place', 'text', $profile?->birth_place],
                ['birth_name', 'birth_name', 'text', $profile?->birth_name],
                ['nationality', 'nationality', 'text', $profile?->nationality],
                ['education', 'school_education', 'text', $profile?->education],
            ] as [$field, $label, $type, $value])
                <div class="flex items-start justify-between gap-4 py-2.5">
                    <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.'.$label) }}</dt>
                    <dd class="min-w-0 flex-1">
                        <x-ui.inline-edit-field :id="'employee-master-'.$field" :field="$field" :type="$type" :can-edit="$canEditMasterData">
                            {{ $value ?: __('app.not_set') }}
                        </x-ui.inline-edit-field>
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="rounded-xl bg-rt-surface p-5 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-rt-glow>
        <h3 class="flex items-center gap-2 text-sm font-semibold text-rt-text dark:text-rt-dark-text">
            <span class="inline-flex h-8 w-8 items-center justify-center rounded-lg bg-rt-accent-soft/70 text-rt-accent dark:bg-rt-dark-accent-soft/60 dark:text-rt-dark-accent">
                <i class="far fa-briefcase text-sm"></i>
            </span>
            {{ __('app.employment_data') }}
        </h3>

        <dl class="mt-4 divide-y divide-rt-border/60 dark:divide-rt-dark-border/60">
            <div class="flex items-start justify-between gap-4 py-2.5">
                <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.company') }}</dt>
                <dd class="pt-2 text-right text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $company['name'] ?? __('app.not_set') }}</dd>
            </div>

            <div class="flex items-start justify-between gap-4 py-2.5">
                <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.form_date') }}</dt>
                <dd class="pt-2 text-right text-sm font-medium text-rt-text dark:text-rt-dark-text">{{ $profile?->updated_at?->format('d.m.Y') ?: __('app.not_set') }}</dd>
            </div>

            @foreach ([
                ['personnel_nr', 'personnel_nr', 'text', $profile?->personnel_nr, []],
                ['position', 'position', 'text', $profile?->position, []],
                ['entry_date', 'entry_date', 'date', $profile?->entry_date?->format('d.m.Y'), []],
                ['multiple_employment', 'multiple_employment', 'select', is_null($profile?->multiple_employment) ? null : ($profile->multiple_employment ? __('app.yes') : __('app.no')), $multipleEmploymentOptions],
                ['employment_type', 'employment_type', 'select', $employmentTypeOptions[$profile?->employment_type ?? ''] ?? $profile?->employment_type, $employmentTypeOptions],
                ['weekly_working_hours', 'weekly_working_hours', 'number', $profile?->weekly_working_hours, []],
            ] as [$field, $label, $type, $value, $options])
                <div class="flex items-start justify-between gap-4 py-2.5">
                    <dt class="shrink-0 pt-2 text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.'.$label) }}</dt>
                    <dd class="min-w-0 flex-1">
                        <x-ui.inline-edit-field
                            :id="'employee-employment-'.$field"
                            :field="$field"
                            :type="$type"
                            :options="$options"
                            :can-edit="$canEditMasterData"
                        >
                            {{ $value ?: __('app.not_set') }}
                        </x-ui.inline-edit-field>
                    </dd>
                </div>
            @endforeach

            <div class="py-2.5">
                <dt class="text-xs uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.additional_information') }}</dt>
                <dd class="mt-2">
                    <x-ui.inline-edit-field
                        id="employee-employment-additional-information"
                        field="additional_information"
                        :can-edit="$canEditMasterData"
                        :multiline="true"
                        :rows="4"
                        align="left"
                    >
                        <span class="whitespace-pre-wrap">{{ $profile?->additional_information ?: __('app.not_set') }}</span>
                    </x-ui.inline-edit-field>
                </dd>
            </div>
        </dl>
    </section>
</div>
