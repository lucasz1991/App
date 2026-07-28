@php
    $compensationTypeOptions = [
        '' => __('app.please_select'),
        'salary' => __('app.salary'),
        'fixed_salary' => __('app.fixed_salary'),
        'hourly_wage' => __('app.hourly_wage'),
    ];
@endphp

<div class="rounded-xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900 dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-100">
    <i class="far fa-shield-check mr-2"></i>{{ __('app.compensation_confidential_hint') }}
</div>

<div class="grid gap-4 lg:grid-cols-2" data-anim-stagger>
    @foreach ([
        ['tax_identification_number', 'tax_identification_number', 'text', $profile?->tax_identification_number, []],
        ['social_security_number', 'social_security_number', 'text', $profile?->social_security_number, []],
        ['iban', 'iban', 'text', $profile?->iban, []],
        ['health_insurance', 'health_insurance', 'text', $profile?->health_insurance, []],
        ['tax_class', 'tax_class', 'text', $profile?->tax_class, []],
        ['children_count', 'children_count', 'number', $profile?->children_count, []],
        ['religion', 'religion', 'text', $profile?->religion, []],
        ['compensation_type', 'compensation_type', 'select', $compensationTypeOptions[$profile?->compensation_type ?? ''] ?? $profile?->compensation_type, $compensationTypeOptions],
        ['compensation_amount', 'compensation_amount', 'number', $profile?->compensation_amount, []],
    ] as [$field, $label, $type, $value, $options])
        <section class="flex items-start justify-between gap-4 rounded-xl bg-rt-surface p-4 shadow-rt-sm ring-1 ring-rt-border/60 dark:bg-rt-dark-surface dark:ring-rt-dark-border/60" data-rt-glow>
            <span class="shrink-0 pt-2 text-xs font-semibold uppercase tracking-wide text-rt-muted dark:text-rt-dark-muted">{{ __('app.'.$label) }}</span>
            <div class="min-w-0 flex-1">
                <x-ui.inline-edit-field
                    :id="'employee-compensation-'.$field"
                    :field="$field"
                    :type="$type"
                    :options="$options"
                    :can-edit="$canEditCompensation"
                >
                    {{ $value ?: __('app.not_set') }}
                </x-ui.inline-edit-field>
            </div>
        </section>
    @endforeach
</div>
